SmartPRS3 - On-Premises Client Package
======================================

This folder IS the client package. Version: see VERSION.txt.
Full guide: SmartPRS3-OnPrem-IIS-Installation-Guide.pdf (in this folder).

BEFORE YOU START (Windows + IIS)
  1. IIS with CGI, and IIS URL Rewrite 2.1                       (guide, section 2)
  2. Visual C++ Redistributable 2015-2022 x64
  3. PHP 8.3 NTS x64 in e.g. C:\php83, php.ini made from php.ini-production with
     extension=curl, fileinfo, gd, mbstring, openssl, pdo_mysql, zip  (guide, section 3)
  4. MySQL 8 (or MariaDB 10.6+) running, and its root password
  Internet to https://smartprs.com for the licence (or an offline .lic file).

INSTALL
  1. Unzip into an EMPTY, short folder, e.g. C:\SmartPRS3
     (use tar if Explorer complains:  tar -xf <zip> --strip-components=1 ).
  2. Double-click  INSTALL-Windows.bat  (it asks for Administrator rights itself).
     You type only the MySQL root password, then press Enter for the database
     name and the port (8085). It then:
       - checks PHP, its extensions and that every file extracted
       - creates the database and its own two accounts (never touches another
         database: if the name is taken it picks a new one)
       - creates the tables (empty - no sample data), gives the app account its rights
       - registers the background jobs (Task Scheduler > SmartPRS3)
       - creates the IIS site and app pool, maps .php to THIS PHP, sets folder
         rights and the firewall, and checks the site answers
  3. Open the address it prints  /activate  and enter the License Code
     (offline server: set SMARTPRS_OFFLINE_LIC=true in .env, send Ametecs the
     fingerprint shown there, upload the .lic file they return).
  4. The sign-in page then asks you to create the administrator.
  Linux: sudo bash INSTALL-Linux.sh  (web root = the public folder).

FORGOTTEN PASSWORD / LICENCE
  Ametecs can set a new password for any login of this install from its Super
  Admin, and can suspend or resume the licence. Both reach this server within a
  few minutes when it is online.

UPDATES
  Administration -> Updates & Licence -> Check for update -> Download & install.
  Never unzip a new client package over a working install.

If anything fails, the installer says which step and why. Fix it and run it
again - it is safe to repeat. VERIFY-INSTALL.bat re-checks the files.
Keep a backup of .env and storage\ - they hold this server's keys.
