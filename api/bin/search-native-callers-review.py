#!/usr/bin/env python3
"""Independent parsed invariants on actual PHP caller exports; no native oracle."""
import json, pathlib, sys
from html.parser import HTMLParser

class DOM(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.lists=[]; self.fragments=[]; self.forms=[]; self.current_form=None
        self.articles=0; self.text=[]
        self.feed(html)
    def handle_starttag(self, tag, attributes):
        attrs=dict(attributes)
        if 'data-search-list' in attrs: self.lists.append(attrs)
        if 'data-search-fragment' in attrs: self.fragments.append(attrs)
        if tag=='article' and 'tweet' in attrs.get('class','').split(): self.articles+=1
        if tag=='form':
            self.current_form={'attributes':attrs,'inputs':{}}
            self.forms.append(self.current_form)
        if tag=='input' and self.current_form is not None and 'name' in attrs:
            self.current_form['inputs'][attrs['name']]=attrs.get('value','')
    def handle_endtag(self, tag):
        if tag=='form': self.current_form=None
    def handle_data(self, value): self.text.append(value)

path=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-search-native-callers-postgres-baseline.json')
export=json.loads(path.read_text()); checks=0
def check(condition, label):
    global checks
    assert condition, label
    checks+=1
for width, cases in export['cases'].items():
    for name, case in cases.items():
        if name.startswith(('invalid_','clamp_')): continue
        window=case['input']; type_=case['type']; captures=case['captures']
        more_flags=window['has_more'] if isinstance(window['has_more'],dict) else {}
        rows={section:window[section] if isinstance(window[section],list) else [] for section in ['accounts','hashtags','statuses']}
        visible={
            'accounts':sum(isinstance(row,dict) for row in rows['accounts']),
            'hashtags':sum(isinstance(row,dict) and row.get('name','')!='' for row in rows['hashtags']),
            'statuses':0 if name=='empty_advanced_denied' else sum(isinstance(row,dict) for row in rows['statuses']),
        }
        first=DOM(captures['full_shared_body']['html'])
        labels=[]
        for section, one, many in [('accounts','account','accounts'),('hashtags','tag','tags'),('statuses','post','posts')]:
            n=len(rows[section]); more=bool(more_flags.get(section))
            labels.append(f'{n}{"+" if more else ""} {one if n==1 and not more else many}')
        check(' · '.join(labels) in ''.join(first.text), f'{width}/{name} raw summary counts')
        check(case['q'] in ''.join(first.text), f'{width}/{name} significant query Unicode')
        expected_sections=[section for section in rows if (type_=='' or type_==section) and rows[section]]
        check([attrs['data-section'] for attrs in first.lists]==expected_sections, f'{width}/{name} selected section order')
        check(first.articles==sum(visible[section] for section in expected_sections), f'{width}/{name} complete visible first cards')
        for attrs in first.lists:
            section=attrs['data-section']
            check(attrs['data-offset']==str(len(rows[section])) and attrs['data-limit']=='20'
                  and attrs['data-resolve']=='1' and attrs['data-q']==case['q'], f'{width}/{name}/{section} initial source cursor')
        for section in rows:
            fragment=DOM(captures['append_'+section]['html'])
            check(len(fragment.fragments)==1, f'{width}/{name}/{section} one actual fragment')
            attrs=fragment.fragments[0]
            check(attrs['data-offset']==str(7+len(rows[section])) and attrs['data-section']==section
                  and attrs['data-has-more']==('1' if rows[section] and more_flags.get(section) else '0'), f'{width}/{name}/{section} raw cursor')
            check(fragment.articles==visible[section], f'{width}/{name}/{section} mandatory visible count')
        if name=='mixed_type_all':
            accounts=DOM(captures['append_accounts']['html']); tags=DOM(captures['append_hashtags']['html'])
            follow=[form for form in accounts.forms if form['inputs'].get('action')=='follow_remote']
            check(len(follow)==1 and follow[0]['attributes'].get('action')=='?view=search'
                  and follow[0]['inputs'].get('return_view')=='search'
                  and follow[0]['inputs'].get('actor_id')=='https://remote.test/users/alice', f'{width} account follow action')
            check('csrf' not in follow[0]['inputs'], f'{width} canonical account CSRF omission recorded')
            tagforms=[form for form in tags.forms if form['inputs'].get('action')=='follow_tag']
            check(len(tagforms)==1 and tagforms[0]['inputs'].get('tag')=='café & tea'
                  and tagforms[0]['inputs'].get('return_view')=='tags', f'{width} tag action target')
            check('Your profile' in ''.join(accounts.text) and 'Manage' in ''.join(tags.text), f'{width} self/followed-tag actions')
        if name.startswith('status_'):
            status=DOM(captures['append_statuses']['html'])
            actions={form['inputs'].get('action') for form in status.forms}
            if name in ('status_own','status_own_snowflake'): check({'delete_status','pin_status'}<=actions, f'{width} current own actions')
            if name=='status_own_snowflake':
                provenance=case['context']['canonical_public_id_provenance']
                check(rows['statuses'][0]['id']==provenance['public_id'] and len(provenance['public_id'])>=18
                      and provenance['local_id']==456, f'{width} actual PHP public snowflake distinct from local row ID')
            if name=='status_saved_flags': check({'unreblog_status','unfavourite_status','unbookmark_status'}<=actions, f'{width} current saved actions')
            if name=='status_poll_open': check('poll_vote' in actions, f'{width} real poll action')
            scoped=[form for form in status.forms if 'csrf' in form['inputs']]
            check(scoped and all(form['inputs']['csrf']=='fixture-csrf-token' for form in scoped), f'{width}/{name} trusted CSRF forms')
            check(all(form['inputs'].get('return_view')=='search' for form in scoped), f'{width}/{name} Search return actions')
        if name=='empty_advanced_denied':
            attrs=DOM(captures['append_statuses']['html']).fragments[0]
            check(attrs['data-offset']=='17' and attrs['data-has-more']=='1' and visible['statuses']==0, f'{width} canonical zero-card advancement')
print(f'search-native-callers-review PASS {checks} parsed controls; widths={",".join(export["widths"])}')
