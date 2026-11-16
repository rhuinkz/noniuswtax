<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/update_history.php';
require_once __DIR__.'/lib/login_slideshow.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');
try{$a=require_member();require_owner($a);$v=db()->query('SELECT VERSION()')->fetchColumn();echo json_encode_safe(['app_version'=>APP_VERSION,'php_version'=>PHP_VERSION,'database_version'=>$v,'migrations'=>migration_status(),'update_history'=>update_history_for_owner(),'login_slideshow'=>login_slideshow_status(ROOT_PATH,config()),'migration_integrity'=>schema_migration_integrity(db()),'zip_extension'=>class_exists('ZipArchive'),'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size')]);}catch(Throwable $e){http_response_code(400);echo json_encode_safe(['error'=>$e->getMessage()]);}
