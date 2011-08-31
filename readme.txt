=== Tyrone the WP Watchdog ===
Contributors: madjax
Tags: monitor
Tested up to: 3.2.1
Stable tag: 0.1.2
Requires at least: 3.2.1

Tyrone turns a WordPress installation into a website monitoring tool. Check the status of your sites, and keep tabs on which need upgrading, and scan for spam and changes.

== Description ==

Tyrone turns a WordPress installation into a website monitoring tool. Check the status of your sites, and keep tabs on which need upgrading, and scan for known spam terms, as well as changes to site content.

== Changelog ==

= 0.1.2 =
* Row actions
* Fix column sorting
* Better email formatting

= 0.1.1 =
* Add the_content filter for sites single view on front end.
* Schedule cron job for automation
* General cleanup
* Catch prowl errors
* Improved site import
* Improved styling
* Better readme

= 0.1.0 =
* Initial Release

== Upgrade Notice ==

= 0.1.0 =
Initial Release

== Installation ==

1. Use automatic installer
1. Update your wp-config.php file to define 'WP_POST_REVISIONS' at a reasonable number below 100
1. Look for new menu item **Sites**
1. Add sites you wish to monitor one at a time, or import using the **Import Sites** menu option
1. Site will be scanned once initially when you publish them
1. Use the **Run Tyrone Prowl** menu option to scan all your sites
1. Active cron job in options if desired. Tyrone will scan every hour. You will need to make sure you are pinging your site somehow to keep the cron running.

== Frequently Asked Questions ==

= Why =

With great power comes great responsibility. Once you get over a certain number of WordPress installations, you need a way to keep track.

= What or Who is Tyrone =

Tyrone was a 100+ lb Rottweiler / Pit Bull mix ( Pitweiler? ), who was my faithful companion from the time I adopted him at 18, to his untimely passing at age 10.