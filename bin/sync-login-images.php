<?php
declare(strict_types=1);
// Cloud sync retired in v0.4.45: slideshow pictures are now managed by the Project Owner in System Settings.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Not available.');}
fwrite(STDERR,"Google Drive synchronization retired in v0.4.45. Manage login pictures in System Settings > Update. Remove any former cron schedule.\n");
exit(0);
