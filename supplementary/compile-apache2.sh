#!/bin/sh
# Compile Apache from source on Debian, mirroring Debian package configuration

apt-get update
apt-get install -y git gcc make autoconf pkg-config libtool libtool-bin libapr1 libapr1-dev libexpat1-dev  libssl-dev libldap-dev
apt-get install -y libpcre3 libpcre3-dev # not found in Debian 13
apt-get install -y apache2
apt-get -y build-dep apache2
cd /usr/src
git clone --depth 1 https://github.com/apache/httpd.git && cd httpd
cd srclib
wget https://dlcdn.apache.org/apr/apr-1.7.6.tar.gz && tar -xvzf apr-1.7.6.tar.gz && rm apr-1.7.6.tar.gz && mv apr-1.7.6 apr
wget https://dlcdn.apache.org/apr/apr-util-1.6.3.tar.gz && tar -xvzf apr-util-1.6.3.tar.gz && rm apr-util-1.6.3.tar.gz && mv apr-util-1.6.3 apr-util
cd ..
./buildconf

./configure \
--with-pcre=/usr \
--enable-mpms-shared=all \
--enable-unixd=static \
--enable-layout=Debian --enable-so \
--with-program-name=apache2  \
--with-ldap=yes --with-ldap-include=/usr/include \
--with-ldap-lib=/usr/lib \
--with-suexec-caller=www-data \
--with-suexec-bin=/usr/lib/apache2/suexec \
--with-suexec-docroot=/var/www \
--with-suexec-userdir=public_html \
--with-suexec-logfile=/var/log/apache2/suexec.log \
--with-suexec-uidmin=100 \
--enable-suexec=shared \
--enable-log-config=static --enable-logio=static \
--enable-version=static \
--with-apr=/usr/bin/apr-1-config \
--with-apr-util=/usr/bin/apu-1-config \
--with-pcre=yes \
--enable-pie \
--enable-shared=most --enable-mods-shared=all

make

# Make sure we compiled the *.so's, or something went wrong
find . -name *.so

cp -r /etc/apache2 /etc/apache2_bak
make install
sed -i 's|IncludeOptional |IncludeOptional /etc/apache2/|' /etc/apache2/apache2.conf
sed -i 's|Include |Include /etc/apache2/|' /etc/apache2/apache2.conf
sed -i 's|/etc/apache2//etc/apache2/|/etc/apache2/|' /etc/apache2/apache2.conf
sed -i 's|/usr/sbin/envvars|/etc/apache2/envvars|' /usr/sbin/apache2ctl

apt-mark hold apache2

# PHP
apt-get install -y re2c libdb-dev libonig-dev libsodium-dev libargon2-dev
apt-get install -y bison libxml2-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev g++
cd /usr/src
git clone --depth 1 https://github.com/php/php-src.git
cd php-src
./buildconf
./configure --with-apxs2=/usr/bin/apxs --disable-cgi --with-config-file-path=/usr/local/lib --with-openssl --enable-calendar --enable-exif --enable-ftp --enable-intl --enable-mbstring --with-mhash --with-mysqli --with-zlib --with-curl --with-gettext --with-password-argon2 --enable-pdo=shared --with-pdo-mysql=shared --without-pdo-sqlite
make

cp -r /etc/php /etc/php_bak
# At least one LoadModule must exist in apache2.conf for PHP's make install to work
echo "LoadModule foo" >> /etc/apache2/apache2.conf
# LoadModule php_module /usr/lib/apache2/modules/libphp.so
# AddType application/x-httpd-php .php
make install
libtool --finish /usr/src/php-src/libs
sed -i 's|LoadModule foo||' /etc/apache2/apache2.conf
sed -i 's|LoadModule php_module         usr/lib/apache2/modules/libphp.so|LoadModule php_module         /usr/lib/apache2/modules/libphp.so|' /etc/apache2/apache2.conf
apache2 -S

# Adjust based on your PHP version
cp /etc/php/8.3/apache2/php.ini /usr/local/lib
sed -i 's|^;extension=mysqli|extension=mysqli|' /usr/local/lib/php.ini
a2enmod php8.3

# If /etc/apache2/mods-enabled/php8.3.conf exists, may need to comment out LoadModule there if we only have libphp.so and libphp8.3.so
ls /usr/lib/apache2/modules/libphp*

# php-imap
apt-get install -y libc-client-dev libkrb5-dev libgssapi-krb5-2 libssl-dev
cd /usr/src
git clone --depth 1 https://github.com/php/pecl-mail-imap.git && cd pecl-mail-imap
phpize
./configure --with-imap --with-kerberos --with-imap-ssl
make
make install

# Restart
/etc/init.d/apache2 restart

# You may need to create a cron job at reboot: @reboot mkdir -p /var/run/apache2
