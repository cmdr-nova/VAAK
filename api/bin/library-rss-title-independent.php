<?php
/** Exact intended delimiter edges; actual canonical helper, no transport. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
reference_load(dirname(__DIR__).'/ap-rss.php',['ap_rss_display_title']);
$cases=[
 [' -–—|: Feed name :|—–- ','Feed name'],
 ["\t\n\r\0\x0B\\-Feed name-\\\x0B\0\r\n\t",'Feed name'],
 ["\u{00A0}Feed name\u{00A0}","\u{00A0}Feed name\u{00A0}"],
 ["\u{2009}Feed name\u{2009}","\u{2009}Feed name\u{2009}"],
 ["\u{2009}Feed name –","\u{2009}Feed name"],
 ["–Feed name\u{2009}","Feed name\u{2009}"],
 ['日本語 Feed 😀','日本語 Feed 😀'],['😀Feed😀','😀Feed😀'],
 ['€Feed€','€Feed€'],['éFeedé','éFeedé'],
 ['—é-日本語-😀—','é-日本語-😀'],['Feed: brand | channel','Feed: brand | channel'],
 ['Feed name RSS','Feed name'],['Feed name blog','Feed name'],
 ['', 'RSS'],['--','--'],["\xFF Feed","\xFF Feed"],
];
foreach($cases as [$input,$expected]){if(ap_rss_display_title($input)!==$expected)throw new RuntimeException('RSS intended-edge mismatch: '.bin2hex($input));}
echo 'library-rss-title-independent: PASS ('.count($cases)." exact edge, interior, multibyte and invalid UTF-8 fallback controls)\n";
