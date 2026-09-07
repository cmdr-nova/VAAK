<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/ap-instance-docs.php';
ap_instance_docs_render_public_page('conduct', ap_instance_rules_list());
