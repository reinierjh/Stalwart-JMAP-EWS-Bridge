#!/usr/bin/env python3
"""Destructive-to-test-data-only live JMAP/EWS integration check; see README-live.md."""

import argparse
import json
import os
import re
import signal
import stat
import subprocess
import sys
import urllib.parse
import uuid
import xml.etree.ElementTree as ET

CORE = 'urn:ietf:params:jmap:core'
MAIL = 'urn:ietf:params:jmap:mail'
CONTACTS = 'urn:ietf:params:jmap:contacts'
CALENDARS = 'urn:ietf:params:jmap:calendars'
M = 'http://schemas.microsoft.com/exchange/services/2006/messages'
T = 'http://schemas.microsoft.com/exchange/services/2006/types'
S = 'http://schemas.xmlsoap.org/soap/envelope/'
NS = {'m': M, 't': T}
ET.register_namespace('s', S)
ET.register_namespace('m', M)
ET.register_namespace('t', T)


class CheckError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CheckError(message)


def verify_config(path):
    st = os.lstat(path)
    require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and not st.st_mode & 0o077,
            'curl config must be a root-owned regular file without group/other access')
    # An arbitrary curl config could disable TLS or add destinations. Never print it.
    with open(path, encoding='utf-8') as config:
        lines = [line.strip() for line in config if line.strip() and not line.lstrip().startswith('#')]
    require(len(lines) == 1 and re.match(r'^user\s*(?:=|\s)\s*\S', lines[0]),
            'curl config must contain only one user option')


def checked_url(url, scheme, localhost=False):
    p = urllib.parse.urlsplit(url)
    require(p.scheme == scheme and p.hostname and not p.username and not p.password
            and not p.fragment, 'endpoint violates URL policy')
    if localhost:
        require(p.hostname in ('localhost', '127.0.0.1', '::1'), 'EWS endpoint must be localhost')
    return url


class LiveTest:
    def __init__(self, args):
        self.args = args
        self.created = {}  # Exact IDs only; never printed.
        self.failures = 0
        self.uncertain = False
        self.marker = uuid.uuid4().hex

    def report(self, label, ok, detail=''):
        print(('PASS ' if ok else 'FAIL ') + label + (': ' + detail if detail else ''), flush=True)
        if not ok:
            self.failures += 1

    def check(self, label, action):
        try:
            action()
            self.report(label, True)
        except CheckError as exc:
            self.report(label, False, str(exc))

    def curl(self, url, data=None, content_type=None, ews=False):
        checked_url(url, 'http' if ews else 'https', ews)
        cmd = ['curl', '-q', '--config', self.args.curl_config, '--proto', '=http' if ews else '=https',
               '--noproxy', '*', '--silent', '--show-error', '--fail-with-body', '--max-time',
               str(self.args.timeout)]
        if content_type:
            cmd += ['-H', 'Content-Type: ' + content_type]
        if ews:
            cmd += ['-H', 'Host: ' + self.args.ews_host]
            parsed = urllib.parse.urlsplit(url)
            if parsed.hostname == 'localhost':
                cmd += ['--resolve', 'localhost:%d:127.0.0.1' % (parsed.port or 80)]
        if data is not None:
            cmd += ['--data-binary', '@-']
        cmd += ['--', url]
        try:
            result = subprocess.run(cmd, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    timeout=self.args.timeout + 5, check=False)
        except subprocess.TimeoutExpired:
            raise CheckError('curl timed out') from None
        require(result.returncode == 0, 'curl failed (exit %d)' % result.returncode)
        return result.stdout

    def jmap(self, method, params):
        data = {'using': [CORE, MAIL, CONTACTS, CALENDARS], 'methodCalls': [[method, params, 'x']]}
        reply = json.loads(self.curl(self.api, json.dumps(data).encode(), 'application/json'))
        responses = reply.get('methodResponses', [])
        require(len(responses) == 1 and responses[0][0] == method, 'JMAP method failed')
        return responses[0][1]

    def setup(self):
        session = json.loads(self.curl(self.args.jmap_session))
        accounts = session.get('primaryAccounts', {})
        self.account = accounts.get(MAIL)
        require(self.account and all(accounts.get(x) == self.account for x in (CONTACTS, CALENDARS)),
                'mail, contacts and calendars must share the primary test account')
        self.api = checked_url(session['apiUrl'], 'https')
        self.upload = checked_url(session['uploadUrl'].replace(
            '{accountId}', urllib.parse.quote(self.account, safe='')), 'https')
        require('{' not in self.upload and '}' not in self.upload, 'unsupported upload URL template')
        lists = {}
        for kind, method in [('mail', 'Mailbox/get'), ('contact', 'AddressBook/get'),
                             ('calendar', 'Calendar/get')]:
            lists[kind] = self.jmap(method, {'accountId': self.account}).get('list', [])
        inbox = [x for x in lists['mail'] if x.get('role') == 'inbox']
        require(len(inbox) == 1 and len(lists['contact']) == 1 and len(lists['calendar']) == 1,
                'expected exactly one inbox, address book and calendar in the test account')
        self.folders = {'mail': inbox[0]['id'], 'contact': lists['contact'][0]['id'],
                        'calendar': lists['calendar'][0]['id']}
        self.report('discovery and account isolation', True)

    def create(self, kind, serial):
        name = 'Synthetic-' + self.marker + '-' + str(serial)
        folder = self.folders[kind]
        args = {'accountId': self.account}
        if kind == 'mail':
            mime = ('From: Synthetic <synthetic@example.invalid>\r\nSubject: ' + name +
                    '\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n' + name + '\r\n').encode()
            blob = json.loads(self.curl(self.upload, mime, 'message/rfc822'))['blobId']
            method = 'Email/import'
            args['emails'] = {'test': {'blobId': blob, 'mailboxIds': {folder: True},
                                       'keywords': {'$seen': True}}}
        elif kind == 'contact':
            method = 'ContactCard/set'
            args['create'] = {'test': {'@type': 'Card', 'addressBookIds': {folder: True},
                                       'name': {'components': [{'kind': 'given', 'value': name},
                                                               {'kind': 'surname', 'value': 'Synthetic'}],
                                                'isOrdered': True}}}
        else:
            method = 'CalendarEvent/set'
            args['create'] = {'test': {'@type': 'Event', 'calendarIds': {folder: True},
                                       'uid': str(uuid.uuid4()), 'title': name, 'description': name,
                                       'start': '2032-01-02T10:00:00', 'timeZone': 'UTC',
                                       'duration': 'PT1H'}}
        # A lost/invalid response after a mutation makes its outcome unknowable.
        self.uncertain = True
        response = self.jmap(method, args)
        if (response.get('notCreated') or {}).get('test'):
            self.uncertain = False
            raise CheckError('JMAP create rejected')
        item = (response.get('created') or {}).get('test')
        require(isinstance(item, dict) and isinstance(item.get('id'), str) and item['id'],
                'JMAP create outcome unknown')
        item_id = item['id']
        self.created.setdefault(kind, []).append(item_id)
        self.uncertain = False
        return item_id, name

    def update(self, kind, item_id, value):
        patches = {'mail': {'keywords/$seen': None},
                   'contact': {'name': {'components': [{'kind': 'given', 'value': value},
                                                       {'kind': 'surname', 'value': 'Synthetic'}],
                                        'isOrdered': True}},
                   'calendar': {'title': value, 'description': value}}
        r = self.jmap(self.method(kind) + '/set', {'accountId': self.account,
                                                   'update': {item_id: patches[kind]}})
        require(item_id in (r.get('updated') or {}), 'JMAP update rejected')

    @staticmethod
    def method(kind):
        return {'mail': 'Email', 'contact': 'ContactCard', 'calendar': 'CalendarEvent'}[kind]

    def destroy(self, kind, item_id):
        r = self.jmap(self.method(kind) + '/set', {'accountId': self.account, 'destroy': [item_id]})
        return item_id in (r.get('destroyed') or [])

    def soap(self, operation, build):
        envelope = ET.Element('{%s}Envelope' % S)
        op = ET.SubElement(ET.SubElement(envelope, '{%s}Body' % S), '{%s}%s' % (M, operation))
        build(op)
        root = ET.fromstring(self.curl(self.args.ews_url, ET.tostring(envelope), 'text/xml; charset=utf-8', True))
        require(root.findtext('.//m:ResponseCode', namespaces=NS) == 'NoError', 'EWS response not NoError')
        return root

    def sync(self, kind, token):
        def build(op):
            shape = ET.SubElement(op, '{%s}ItemShape' % M)
            ET.SubElement(shape, '{%s}BaseShape' % T).text = 'AllProperties'
            folder = ET.SubElement(op, '{%s}SyncFolderId' % M)
            prefix = {'mail': '', 'contact': 'ab_', 'calendar': 'cal_'}[kind]
            ET.SubElement(folder, '{%s}FolderId' % T, {'Id': prefix + self.folders[kind]})
            if token is not None:
                ET.SubElement(op, '{%s}SyncState' % M).text = token
            ET.SubElement(op, '{%s}MaxChangesReturned' % M).text = '1'
        root = self.soap('SyncFolderItems', build)
        state = root.findtext('.//m:SyncState', namespaces=NS)
        last = root.findtext('.//m:IncludesLastItemInRange', namespaces=NS)
        require(state and last in ('true', 'false'), 'invalid sync response')
        changes = root.find('.//m:Changes', NS)
        require(changes is not None and len(changes) <= 1, 'sync page exceeds requested size')
        entries = []
        for change in changes:
            item = change.find('.//t:ItemId', NS)
            require(item is not None and item.get('Id'), 'sync change missing ItemId')
            entries.append((change.tag.rsplit('}', 1)[-1], item.get('Id'), item.get('ChangeKey')))
        return state, last == 'true', entries

    def drain(self, kind, token, first=None):
        seen = set()
        entries = []
        for _ in range(self.args.max_pages):
            state, last, page = first if first is not None else self.sync(kind, token)
            first = None
            for entry in page:
                require(entry not in seen, 'duplicate change across pages')
                seen.add(entry)
                entries.append(entry)
            if last:
                return state, entries
            require(state != token, 'pagination token did not advance')
            token = state
        raise CheckError('pagination page limit reached')

    def get(self, item_id):
        def build(op):
            shape = ET.SubElement(op, '{%s}ItemShape' % M)
            ET.SubElement(shape, '{%s}BaseShape' % T).text = 'AllProperties'
            ET.SubElement(shape, '{%s}BodyType' % T).text = 'Text'
            ET.SubElement(ET.SubElement(op, '{%s}ItemIds' % M), '{%s}ItemId' % T, {'Id': item_id})
        root = self.soap('GetItem', build)
        item = root.find('.//m:Items/*', NS)
        require(item is not None, 'GetItem missing item')
        ident = item.find('t:ItemId', NS)
        require(ident is not None and ident.get('Id') == item_id and ident.get('ChangeKey'),
                'GetItem ID or ChangeKey missing')
        return item, ident.get('ChangeKey')

    def fields(self, kind, item, name, updated=False):
        def text(field):
            return item.findtext('t:' + field, namespaces=NS)
        if kind == 'mail':
            require(text('Subject') == name and (text('Body') or '').strip() == name,
                    'mail subject/body mapping mismatch')
            require(text('IsRead') == ('false' if updated else 'true'), 'mail read state mismatch')
        elif kind == 'contact':
            require(text('GivenName') == name and text('Surname') == 'Synthetic', 'contact name mapping mismatch')
        else:
            require(text('Subject') == name and (text('Body') or '').strip() == name,
                    'calendar subject/body mapping mismatch')
            require(text('Start') == '2032-01-02T10:00:00Z' and text('End') == '2032-01-02T11:00:00Z',
                    'calendar time mapping mismatch')

    def run_kind(self, kind):
        # Seed two known items, then establish a complete baseline before new changes.
        a, name = self.create(kind, 0)
        b, _ = self.create(kind, 1)
        self.report(kind + ' seed create', True)
        baseline, before = self.drain(kind, None)
        prefix = {'mail': '', 'contact': 'ab_', 'calendar': 'cal_'}[kind]
        aid, bid = prefix + a, prefix + b
        seed = [x for x in before if x[0] == 'Create' and x[1] == aid]
        require(len(seed) == 1 and any(x[0] == 'Create' and x[1] == bid for x in before),
                'baseline missing seed items')
        item, old_key = self.get(seed[0][1])  # use the ItemId returned by sync
        self.report(kind + ' baseline and GetItem ID', True)
        self.check(kind + ' baseline ChangeKey', lambda: require(
            seed[0][2] and seed[0][2] == old_key, 'sync/GetItem ChangeKey mismatch'))
        self.check(kind + ' baseline fields', lambda: self.fields(kind, item, name))

        c, _ = self.create(kind, 2)
        d, _ = self.create(kind, 3)
        self.report(kind + ' additional creates', True)
        first = self.sync(kind, baseline)
        retry = self.sync(kind, baseline)
        require(first[1:] == retry[1:], 'retry with identical token/shape returned a different page')
        require(not first[1] and len(first[2]) == 1, 'expected multiple pages of changes')
        self.report(kind + ' page size and retry', True)

        updated = name if kind == 'mail' else name + '-updated'
        self.update(kind, a, updated)
        require(self.destroy(kind, b), 'JMAP delete rejected')
        self.report(kind + ' update and delete between pages', True)
        continuation, changes = self.drain(kind, baseline, first)
        continuation, followup = self.drain(kind, continuation)
        all_changes = changes + followup
        require(len(all_changes) == len(set(all_changes)), 'duplicate change across continuation/follow-up')
        require(all(any(x[0] == 'Create' and x[1] == prefix + item_id for x in all_changes)
                    for item_id in (c, d)), 'created item missing from pages/follow-up')
        updates = [x for x in all_changes if x[0] in ('Update', 'ReadFlagChange') and x[1] == aid]
        require(updates and any(x[0] == 'Delete' and x[1] == bid for x in all_changes),
                'update or delete missing from pages/follow-up')
        self.report(kind + ' paging and follow-up changes', True)
        item, new_key = self.get(aid)
        self.check(kind + ' updated GetItem fields', lambda: self.fields(kind, item, updated, True))
        def check_keys():
            require(new_key != old_key, 'ChangeKey did not change after update')
            # ReadFlagChange may carry no ChangeKey; ordinary Update must match GetItem.
            for change in updates:
                if change[0] == 'Update':
                    require(change[2] and change[2] == new_key, 'update sync/GetItem ChangeKey mismatch')
        self.check(kind + ' updated ChangeKey', check_keys)

    def cleanup(self):
        for kind, ids in self.created.items():
            for item_id in ids:
                try:
                    # The between-pages deletion may already have removed this exact ID.
                    self.destroy(kind, item_id)
                except Exception:
                    pass
                try:
                    r = self.jmap(self.method(kind) + '/get', {'accountId': self.account, 'ids': [item_id]})
                    ok = item_id in (r.get('notFound') or []) and not r.get('list')
                    self.report(kind + ' cleanup verified absent', ok)
                except Exception:
                    self.report(kind + ' cleanup verified absent', False, 'verification failed')
        if self.uncertain:
            self.report('cleanup completeness', False, 'a create outcome is unknown; manual reconciliation required')


def interrupted(signum, frame):
    raise KeyboardInterrupt


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--jmap-session', required=True, help='HTTPS JMAP session URL')
    parser.add_argument('--ews-url', required=True, help='localhost HTTP EWS endpoint')
    parser.add_argument('--ews-host', required=True, help='EWS virtual Host header')
    parser.add_argument('--curl-config', default='/root/.config/ews-bridge-test/curl.conf')
    parser.add_argument('--timeout', type=int, default=30)
    parser.add_argument('--max-pages', type=int, default=200)
    parser.add_argument('--confirm-test-account', action='store_true', required=True,
                        help='confirm the curl credentials are exclusively for the dedicated test account')
    args = parser.parse_args()
    test = LiveTest(args)
    try:
        require(os.geteuid() == 0, 'run as root on the bridge')
        require(args.timeout > 0 and args.max_pages > 0, 'limits must be positive')
        require(re.fullmatch(r'[A-Za-z0-9.\-]+(?::[0-9]+)?', args.ews_host) is not None,
                'invalid Host header')
        verify_config(args.curl_config)
        checked_url(args.jmap_session, 'https')
        checked_url(args.ews_url, 'http', True)
        signal.signal(signal.SIGTERM, interrupted)
        test.setup()
        for kind in ('mail', 'contact', 'calendar'):
            try:
                test.run_kind(kind)
            except CheckError as exc:
                test.report(kind, False, str(exc))
                if test.uncertain:
                    break
            except Exception:
                test.report(kind, False, 'unexpected or malformed response')
                if test.uncertain:
                    break
    except KeyboardInterrupt:
        test.report('interrupted', False, 'cleanup will run')
    except CheckError as exc:
        test.report('setup', False, str(exc))
    except Exception:
        test.report('setup', False, 'unexpected or malformed response')
    finally:
        # Once cleanup starts, ordinary repeated interrupts must not abort it.
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        test.cleanup()
    print('SUMMARY failures=%d cleanup_ids=%d' % (test.failures, sum(map(len, test.created.values()))), flush=True)
    return 1 if test.failures else 0


if __name__ == '__main__':
    sys.exit(main())
