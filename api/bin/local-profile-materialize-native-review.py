#!/usr/bin/env python3
"""Compare exact actual selection/projection key sets; never rewrite expectations."""
import json,sys
reference=json.load(open(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json'))['captures']
native=json.load(open(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-profile-materialize-sixth-projection.json'))
def indexed(rows):
 result={}
 for row in rows:
  key=(row['width'],row['case'],row['branch'])
  assert key not in result,('duplicate case',key)
  result[key]=row
 return result
expected,actual=indexed(reference),indexed(native)
assert expected.keys()==actual.keys(),('key coverage',expected.keys()-actual.keys(),actual.keys()-expected.keys())
assert len(expected)==52
for key,row in expected.items():
 output=actual[key];value=output['result'];page=row['caller_provenance']['actual_append_selected'];index=row['source_index']
 assert value.get('status')==row['source'],('complete status mismatch',key,value.get('fallback'))
 assert value['selected_source']==page['items'][index],('source ordinal binding',key)
 assert value['source_count']==len(page['items']) and value['next_offset']==page['next_offset'] and value['has_more']==page['has_more'],('consumed page cursor',key)
 assert output['original_source']==row['source'] and output['caller_provenance']==row['caller_provenance'],('reference provenance',key)
print('52 exact native/current PHP projections, source bindings, cursor metadata and complete unique key sets passed')
