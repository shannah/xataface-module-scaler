Xataface Scaler Module
Created January 16, 2012
Author Steve Hannah <steve@weblite.ca>
	

Synopsis:
=========

The Xataface Scaler module is a light-weight output cache that works very similar to the existing Xataface output cache but much faster because it can run without needing to load any of the Xataface runtime at cache time. 


License:
=========

This module is released under the terms of the GNU Pulic License version 2
	

Installation Method #1 (Using ANT)
==================================

This first method requires you to have ANT installed on your system.
1. Download the scaler directory and copy it into your application's modules directory. (e.g. $app_path/modules/scaler)
2. In the scaler directory, at the prompt enter:
$ ant
(Don't include the dollar sign).
3. Ensure that the scaler directory is not served by the web server.  You can do this by pointing your web browser to this readme file (e.g. http://example.com/path/to/myapp/modules/scaler/readme.txt).  If everything is installed correctly, it should give you a "Forbidden Error".  If you see this file, then you need to take steps to prevent your web server from serving the scaler directory.
4. Include the scaler/cache.php file at the very beginning of your application's index.php file as follows:

include 'modules/scaler/cache.php';

Installation Method #2 (Manual Installation)
=============================================

1. Download the scaler directory and copy it into your application's modules folder.
2. Make the scaler/cache directory writable by the web server.
3. Make the scaler/key.php file writable by the web server.
4. Ensure that the web server does not serve any pages inside the scaler directory.  It comes with an .htaccess file that should accomplish this if you are running Apache, but if you are on a different environment you may need to use another solution to achieve this end.
5. Delete the contents of the key.php file.  A new key will be generated automatically the first time it runs.  (Don't delete the file... just its contents).
6. Include the scaler/cache.php file at the very beginning of your application's index.php file as follows:

include 'modules/scaler/cache.php';

7. If you want to have some management actions (such as clearing the cache) you should also
add the following to the [_modules] section of your conf.ini file.

	modules_scaler=modules/scaler/scaler.php
	

How It Works?
=============

On each page load, this will store a cached copy of the page inside the cache directory.  Each cache entry records the tables that were used to generate its page.  The next time that page is loaded (with the same environment settings) it will check to see if a cached version exists and it will display that cached version as long as the table modification times for all tables referenced by the cache entry are prior to the modification time of the cache entry itself.


