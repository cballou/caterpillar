DROP TABLE IF EXISTS `crawl_index`;
CREATE TABLE `crawl_index` (
  `link` varchar(255) NOT NULL,
  `id` int(10) unsigned NOT NULL auto_increment,
  `count` int(11) unsigned default '1',
  `contenthash` varchar(32) default NULL,
  `filesize` int(11) unsigned default '0',
  `last_update` datetime NOT NULL default '0000-00-00 00:00:00',
  `last_tested` datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (`id`),
  UNIQUE KEY `link` (`link`)
) ENGINE=MyISAM CHARSET=utf8;
