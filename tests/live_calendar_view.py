#!/usr/bin/env python3
"""Live CalendarView test. Only run against a dedicated test account and staging EWS."""

import argparse
import json
import os
import re
import signal
import sys
import urllib.parse
import uuid
import xml.etree.ElementTree as ET

from live_ews import LiveTest, CheckError, require, verify_config, checked_url, interrupted, CORE, CALENDARS, M, T, S, NS

def safe_type(value):
    return value if isinstance(value, str) and re.fullmatch(r'[A-Za-z][A-Za-z0-9]{0,63}', value) else 'unknown'


def https_origin(url):
    require(isinstance(url, str) and not any(ord(c) < 32 or ord(c) == 127 for c in url),
            'invalid HTTPS endpoint')
    try:
        checked_url(url, 'https')
        parsed = urllib.parse.urlsplit(url)
        require(parsed.username is None and parsed.password is None and '@' not in parsed.netloc,
                'credentials in endpoint URL are forbidden')
        port = parsed.port if parsed.port is not None else 443
        require(1 <= port <= 65535, 'invalid HTTPS port')
        return parsed.hostname, port
    except ValueError:
        raise CheckError('invalid HTTPS endpoint') from None


def checked_api_url(session_url, api_url):
    require(https_origin(session_url) == https_origin(api_url), 'JMAP API host or port mismatch')
    return api_url


class CalendarTest(LiveTest):
    def jmap(self, method, params):
        payload = {'using': [CORE, CALENDARS], 'methodCalls': [[method, params, 'x']]}
        response = json.loads(self.curl(self.api, json.dumps(payload).encode(), 'application/json'))
        calls = response.get('methodResponses', [])
        if len(calls) == 1 and calls[0][0] == 'error':
            raise CheckError('JMAP ' + safe_type(calls[0][1].get('type')))
        require(len(calls) == 1 and calls[0][0] == method, 'invalid JMAP response')
        return calls[0][1]

    def setup(self):
        https_origin(self.args.jmap_session)
        session = json.loads(self.curl(self.args.jmap_session))
        self.account = session.get('primaryAccounts', {}).get(CALENDARS)
        require(isinstance(self.account, str) and self.account, 'calendar account unavailable')
        self.api = checked_api_url(self.args.jmap_session, session['apiUrl'])
        result = self.jmap('Calendar/get', {'accountId': self.account})
        calendars = result.get('list', [])
        require(len(calendars) == 1, 'expected exactly one test calendar')
        self.calendar = calendars[0]['id']
        root = self.soap_code('SyncFolderHierarchy', lambda op: self.hierarchy_shape(op))
        folders = [el.get('Id') for el in root.findall('.//t:CalendarFolder/t:FolderId', NS)]
        matches = [folder for folder in folders if folder == 'cal_' + self.calendar]
        require(len(matches) == 1, 'EWS hierarchy calendar missing or ambiguous')
        self.folder = matches[0]
        self.report('HTTPS session, API host and hierarchy folder', True)

    @staticmethod
    def hierarchy_shape(op):
        shape = ET.SubElement(op, '{%s}FolderShape' % M)
        ET.SubElement(shape, '{%s}BaseShape' % T).text = 'Default'
        folder = ET.SubElement(op, '{%s}SyncFolderId' % M)
        ET.SubElement(folder, '{%s}DistinguishedFolderId' % T, {'Id': 'msgfolderroot'})

    def soap_code(self, operation, build):
        env = ET.Element('{%s}Envelope' % S)
        op = ET.SubElement(ET.SubElement(env, '{%s}Body' % S), '{%s}%s' % (M, operation))
        build(op)
        root = ET.fromstring(self.curl(self.args.ews_url, ET.tostring(env), 'text/xml; charset=utf-8', True))
        code = root.findtext('.//m:ResponseCode', namespaces=NS)
        require(code == 'NoError', operation + ' EWS ' + safe_type(code))
        return root

    def create_event(self, label, obj):
        self.uncertain = True
        result = self.jmap('CalendarEvent/set', {'accountId': self.account, 'sendSchedulingMessages': False,
                                                  'create': {'test': obj}})
        error = (result.get('notCreated') or {}).get('test')
        if error:
            self.uncertain = False
            raise CheckError(label + ' create JMAP ' + safe_type(error.get('type')))
        item = (result.get('created') or {}).get('test')
        require(isinstance(item, dict) and isinstance(item.get('id'), str) and item['id'],
                label + ' create outcome unknown')
        self.created.setdefault('calendar', []).append(item['id'])
        self.uncertain = False
        self.report(label + ' JMAP create', True)
        return item['id']

    def view(self, start, end, maximum=20):
        def build(op):
            op.set('Traversal', 'Shallow')
            shape = ET.SubElement(op, '{%s}ItemShape' % M)
            ET.SubElement(shape, '{%s}BaseShape' % T).text = 'AllProperties'
            ET.SubElement(op, '{%s}CalendarView' % M,
                          {'StartDate': start, 'EndDate': end, 'MaxEntriesReturned': str(maximum)})
            folder = ET.SubElement(op, '{%s}ParentFolderIds' % M)
            ET.SubElement(folder, '{%s}FolderId' % T, {'Id': self.folder})
        root = self.soap_code('FindItem', build)
        folder = root.find('.//m:RootFolder', NS)
        require(folder is not None and folder.find('t:Items', NS) is not None,
                'FindItem missing RootFolder or t:Items')
        require(folder.get('TotalItemsInView', '').isdigit() and
                folder.get('IncludesLastItemInRange') in ('true', 'false') and
                folder.get('IndexedPagingOffset') is None, 'FindItem invalid RootFolder fields')
        items = folder.find('t:Items', NS)
        require(all(item.tag == '{%s}CalendarItem' % T for item in items), 'FindItem unexpected item type')
        return int(folder.get('TotalItemsInView')), folder.get('IncludesLastItemInRange'), list(items)

    @staticmethod
    def projection(item):
        ident = item.find('t:ItemId', NS)
        require(ident is not None and ident.get('Id') and re.fullmatch(r'[0-9a-f]{64}', ident.get('ChangeKey', '')),
                'item ID or full ChangeKey missing')
        return (ident.get('Id'), ident.get('ChangeKey'), item.findtext('t:Start', namespaces=NS),
                item.findtext('t:End', namespaces=NS), item.findtext('t:CalendarItemType', namespaces=NS))

    def get_projection(self, item_id):
        def build(op):
            shape = ET.SubElement(op, '{%s}ItemShape' % M)
            ET.SubElement(shape, '{%s}BaseShape' % T).text = 'AllProperties'
            ids = ET.SubElement(op, '{%s}ItemIds' % M)
            ET.SubElement(ids, '{%s}ItemId' % T, {'Id': item_id})
        root = self.soap_code('GetItem', build)
        items = root.findall('.//m:Items/t:CalendarItem', NS)
        require(len(items) == 1, 'GetItem missing or duplicate CalendarItem')
        return self.projection(items[0]), items[0]

    @staticmethod
    def visible_fields(item):
        # FindItem deliberately omits Body and Attachments. Compare every other
        # returned field, including attributes and nested elements, without logging values.
        def tree(node):
            return (node.tag, tuple(sorted(node.attrib.items())), node.text or '',
                    tuple(tree(child) for child in node))
        return tuple(tree(child) for child in item
                     if child.tag not in ('{%s}Body' % T, '{%s}Attachments' % T))

    def run(self):
        marker = uuid.uuid4().hex
        common = {'@type': 'Event', 'calendarIds': {self.calendar: True}, 'duration': 'PT1H'}
        series = dict(common, uid=str(uuid.uuid4()), title='Synthetic-' + marker,
                      start='2025-03-29T09:00:00', timeZone='Europe/Amsterdam',
                      recurrenceRule={'@type': 'RecurrenceRule', 'frequency': 'daily', 'count': 4},
                      recurrenceOverrides={'2025-03-30T09:00:00': {'start': '2025-03-30T12:00:00'},
                                           '2025-03-31T09:00:00': {'excluded': True}})
        single = dict(common, uid=str(uuid.uuid4()), title='Synthetic-single-' + marker,
                      start='2025-04-02T09:00:00', timeZone='Etc/UTC')
        self.create_event('series', series)
        single_id = self.create_event('singleton', single)

        # Verify the server persisted the requested single rule and both overrides.
        master = self.jmap('CalendarEvent/get', {'accountId': self.account, 'ids': [self.created['calendar'][0]]})
        entries = master.get('list', [])
        require(len(entries) == 1 and entries[0].get('recurrenceRule', {}).get('frequency') == 'daily' and
                entries[0].get('recurrenceRule', {}).get('count') == 4 and
                (entries[0].get('recurrenceOverrides') or {}).get('2025-03-30T09:00:00', {}).get('start') == '2025-03-30T12:00:00' and
                (entries[0].get('recurrenceOverrides') or {}).get('2025-03-31T09:00:00', {}).get('excluded') is True,
                'JMAP recurrence/override roundtrip mismatch')
        self.report('JMAP recurrence/override roundtrip', True)

        start, end = '2025-03-28T00:00:00Z', '2025-04-03T00:00:00Z'
        base = None
        try:
            total, last, items = self.view(start, end)
            require(total == 4 and last == 'true' and len(items) == 4, 'full view root/count mismatch')
            items_full = items
            base = [self.projection(item) for item in items]
            expected = [('2025-03-29T08:00:00Z', '2025-03-29T09:00:00Z', 'Occurrence'),
                        ('2025-03-30T10:00:00Z', '2025-03-30T11:00:00Z', 'Exception'),
                        ('2025-04-01T07:00:00Z', '2025-04-01T08:00:00Z', 'Occurrence'),
                        ('2025-04-02T09:00:00Z', '2025-04-02T10:00:00Z', 'Single')]
            require([x[2:] for x in base] == expected, 'occurrence DST/move/exclusion/singleton mismatch')
            require(len({x[0] for x in base}) == 4 and base[3][0] == 'cal_' + single_id,
                    'view item identity mismatch')
            require(len({x[1] for x in base[:3]}) == 1, 'series full ChangeKey mismatch')
            self.report('full view root, three occurrences, exclusion and UTC singleton', True)
        except CheckError as exc:
            self.report('full view', False, str(exc))
            base = None

        try:
            total, last, items = self.view(start, end, 2)
            projection = [self.projection(item) for item in items]
            require(total == 4 and last == 'false' and len(projection) == 2, 'truncated root/count mismatch')
            if base is not None:
                require(projection == base[:2], 'truncated view differs from full view')
            self.report('MaxEntriesReturned truncation', True)
        except CheckError as exc:
            self.report('MaxEntriesReturned truncation', False, str(exc))

        try:
            total, last, items = self.view(start, end)
            require(base is not None and total == 4 and last == 'true' and
                    [self.projection(item) for item in items] == base, 'repeat view identity or projection mismatch')
            self.report('repeated view stable IDs and ChangeKeys', True)
        except CheckError as exc:
            self.report('repeated view', False, str(exc))

        try:
            total, last, items = self.view('2025-04-02T10:00:00Z', '2025-04-02T11:00:00Z')
            values = [self.projection(item) for item in items]
            require(total == 1 and last == 'true' and len(values) == 1 and values[0][0] == 'cal_' + single_id,
                    'end-at-start boundary mismatch')
            self.report('boundary: event end equals view start', True)
        except CheckError as exc:
            self.report('boundary', False, str(exc))

        if base is None:
            self.report('GetItem comparisons', False, 'skipped: no valid full view')
        else:
            for index, value in enumerate(base, 1):
                try:
                    projection, item = self.get_projection(value[0])
                    require(projection == value and self.visible_fields(item) == self.visible_fields(items_full[index - 1]),
                            'GetItem/view fields or full ChangeKey mismatch')
                    self.report('GetItem item %d versus view' % index, True)
                except CheckError as exc:
                    self.report('GetItem item %d' % index, False, str(exc))

    def cleanup(self):
        for item_id in self.created.get('calendar', []):
            try:
                self.jmap('CalendarEvent/set', {'accountId': self.account, 'sendSchedulingMessages': False,
                                               'destroy': [item_id]})
            except Exception:
                pass
            try:
                result = self.jmap('CalendarEvent/get', {'accountId': self.account, 'ids': [item_id]})
                self.report('cleanup exact ID JMAP notFound', item_id in (result.get('notFound') or []) and not result.get('list'))
            except Exception:
                self.report('cleanup exact ID JMAP notFound', False, 'verification failed')
        if self.uncertain:
            self.report('cleanup completeness', False, 'create outcome unknown; manual reconciliation required')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--jmap-session', required=True)
    parser.add_argument('--ews-url', required=True)
    parser.add_argument('--ews-host', required=True)
    parser.add_argument('--curl-config', required=True)
    parser.add_argument('--timeout', type=int, default=30)
    parser.add_argument('--confirm-test-account', action='store_true', required=True)
    args = parser.parse_args()
    test = CalendarTest(args)
    try:
        require(os.geteuid() == 0, 'must run as root')
        require(args.timeout > 0, 'timeout must be positive')
        require(re.fullmatch(r'[A-Za-z0-9.\-]+(?::[0-9]+)?', args.ews_host) is not None, 'invalid Host header')
        verify_config(args.curl_config)
        checked_url(args.ews_url, 'http', True)
        signal.signal(signal.SIGTERM, interrupted)
        test.setup()
        test.run()
    except KeyboardInterrupt:
        test.report('interrupted', False, 'cleanup will run')
    except CheckError as exc:
        test.report('test', False, str(exc))
    except Exception:
        test.report('test', False, 'unexpected or malformed response')
    finally:
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        test.cleanup()
    print('SUMMARY failures=%d cleanup_ids=%d' % (test.failures, len(test.created.get('calendar', []))), flush=True)
    return 1 if test.failures else 0


def self_test():
    import types
    import unittest

    class OriginTests(unittest.TestCase):
        def test_same_host_and_equivalent_port(self):
            for session, api in [
                ('https://example.invalid/session', 'https://example.invalid/api'),
                ('https://EXAMPLE.invalid:443/session', 'https://example.invalid/api'),
                ('https://example.invalid/session', 'https://example.invalid:443/api'),
                ('https://example.invalid:8443/session', 'https://example.invalid:8443/api'),
            ]:
                with self.subTest(case=(session, api)):
                    self.assertEqual(checked_api_url(session, api), api)

        def test_other_host_or_port_rejected(self):
            for api in ('https://other.invalid/api', 'https://example.invalid:8443/api',
                        'https://example.invalid:0/api', 'https://example.invalid:99999/api',
                        'http://example.invalid/api'):
                with self.subTest(case=api), self.assertRaises(CheckError):
                    checked_api_url('https://example.invalid/session', api)
            with self.assertRaises(CheckError):
                checked_api_url('https://example.invalid:8443/session', 'https://example.invalid/api')

        def test_credentials_rejected_in_either_url(self):
            for url in ('https://user:password@example.invalid/path', 'https://user@example.invalid/path',
                        'https://@example.invalid/path'):
                for is_session in (True, False):
                    with self.subTest(is_session=is_session), self.assertRaises(CheckError):
                        checked_api_url(url if is_session else 'https://example.invalid/session',
                                        'https://example.invalid/api' if is_session else url)

        def test_mismatch_stops_before_api_request(self):
            for api in ('https://other.invalid/api', 'https://example.invalid:8443/api',
                        'https://user:password@example.invalid/api'):
                with self.subTest():
                    test = CalendarTest(types.SimpleNamespace(jmap_session='https://example.invalid/session'))
                    requests = []
                    def curl(url):
                        requests.append(url)
                        return json.dumps({'primaryAccounts': {CALENDARS: 'fixture'}, 'apiUrl': api}).encode()
                    test.curl = curl
                    with self.assertRaises(CheckError):
                        test.setup()
                    self.assertEqual(requests, ['https://example.invalid/session'])

        def test_invalid_session_stops_before_request(self):
            test = CalendarTest(types.SimpleNamespace(jmap_session='https://user:password@example.invalid/session'))
            test.curl = lambda url: self.fail('request must not be made')
            with self.assertRaises(CheckError):
                test.setup()

    suite = unittest.defaultTestLoader.loadTestsFromTestCase(OriginTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    sys.exit(0 if result.wasSuccessful() else 1)


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        self_test()
    else:
        sys.exit(main())
