<?php

/*!
 * Watcher Class
 *
 * Copyright (c) 2013-2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Watches the source/ dir for any changes so those changes can be automagically
 * moved to the public/ dir. Watches static files, patterns, and data files
 *
 * This is not the most efficient implementation of a directory watch but I hope
 * it's the most platform agnostic.
 *
 */

namespace PatternLab;

use \PatternLab\Annotations;
use \PatternLab\Builder;
use \PatternLab\Config;
use \PatternLab\Console;
use \PatternLab\Data;
use \PatternLab\FileUtil;
use \PatternLab\PatternData;
use \PatternLab\Util;
use \PatternLab\Timer;

class Watcher extends Builder {
	
	/**
	* Use the Builder __construct to gather the config variables
	*/
	public function __construct($config = array()) {
		
		// construct the parent
		parent::__construct($config);
		
	}
	
	/**
	* Watch the source/ directory for any changes to existing files. Will run forever if given the chance.
	* @param  {Boolean}       decide if the reload server should be turned on
	* @param  {Boolean}       decide if static files like CSS and JS should be moved
	*/
	public function watch($options = array()) {
		
		// double-checks options was properly set
		if (empty($options)) {
			Console::writeLine("<error>need to pass options to generate...</error>");
			exit;
		}
		
		// set default attributes
		$reload        = (isset($options["autoReload"])) ? $options["autoReload"] : false;
		$moveStatic    = (isset($options["moveStatic"])) ? $options["moveStatic"] : true;
		$noCacheBuster = (isset($options["noCacheBuster"])) ? $options["noCacheBuster"] : false;
		
		// automatically start the auto-refresh tool
		if ($reload) {
			$path = str_replace("lib".DIRECTORY_SEPARATOR."PatternLab","autoReloadServer.php",__DIR__);
			$fp = popen("php ".$path." -s", "r"); 
			Console::writeLine("starting page auto-reload...");
		}
		
		if ($noCacheBuster) {
			Config::updateOption("cacheBuster",0);
		}
		
		$c  = false;           // track that one loop through the pattern file listing has completed
		$o  = new \stdClass(); // create an object to hold the properties
		$cp = new \stdClass(); // create an object to hold a clone of $o
		
		$o->patterns = new \stdClass();
		
		Console::writeLine("watching your site for changes...");
		
		// default vars
		$publicDir = Config::getOption("publicDir");
		$sourceDir = Config::getOption("sourceDir");
		
		// run forever
		while (true) {
			
			// clone the patterns so they can be checked in case something gets deleted
			$cp = clone $o->patterns;
			
			// iterate over the patterns & related data and regenerate the entire site if they've changed
			$patternObjects  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir."/_patterns/"), \RecursiveIteratorIterator::SELF_FIRST);
			
			// make sure dots are skipped
			$patternObjects->setFlags(\FilesystemIterator::SKIP_DOTS);
			
			foreach($patternObjects as $name => $object) {
					
				// clean-up the file name and make sure it's not one of the pattern lab files or to be ignored
				$fileName      = str_replace($sourceDir."/_patterns".DIRECTORY_SEPARATOR,"",$name);
				$fileNameClean = str_replace(DIRECTORY_SEPARATOR."_",DIRECTORY_SEPARATOR,$fileName);
				
				if ($object->isFile() && (($object->getExtension() == "mustache") || ($object->getExtension() == "json") || ($object->getExtension() == "md"))) {
					
					// make sure this isn't a hidden pattern
					$patternParts = explode(DIRECTORY_SEPARATOR,$fileName);
					$pattern      = isset($patternParts[2]) ? $patternParts[2] : $patternParts[1];
					
					// make sure the pattern still exists in source just in case it's been deleted during the iteration
					if (file_exists($name)) {
						
						$mt = $object->getMTime();
						if (isset($o->patterns->$fileName) && ($o->patterns->$fileName != $mt)) {
							$o->patterns->$fileName = $mt;
							$this->updateSite($fileName,"changed");
						} else if (!isset($o->patterns->$fileName) && $c) {
							$o->patterns->$fileName = $mt;
							$this->updateSite($fileName,"added");
							if ($object->getExtension() == "mustache") {
								$patternSrcPath  = str_replace(".mustache","",$fileName);
								$patternDestPath = str_replace("/","-",$patternSrcPath);
								$render = ($pattern[0] != "_") ? true : false;
								$this->patternPaths[$patternParts[0]][$pattern] = array("patternSrcPath" => $patternSrcPath, "patternDestPath" => $patternDestPath, "render" => $render);
							}
						} else if (!isset($o->patterns->$fileName)) {
							$o->patterns->$fileName = $mt;
						}
						
						if ($c && isset($o->patterns->$fileName)) {
							unset($cp->$fileName);
						}
						
					} else {
						
						// the file was removed during the iteration so remove references to the item
						unset($o->patterns->$fileName);
						unset($cp->$fileName);
						unset($this->patternPaths[$patternParts[0]][$pattern]);
						$this->updateSite($fileName,"removed");
						
					}
					
				}
				
			}
			
			// make sure old entries are deleted
			// will throw "pattern not found" errors if an entire directory is removed at once but that shouldn't be a big deal
			if ($c) {
				
				foreach($cp as $fileName => $mt) {
					
					unset($o->patterns->$fileName);
					$patternParts = explode(DIRECTORY_SEPARATOR,$fileName);
					$pattern = isset($patternParts[2]) ? $patternParts[2] : $patternParts[1];
					
					unset($this->patternPaths[$patternParts[0]][$pattern]);
					$this->updateSite($fileName,"removed");
					
				}
				
			}
			
			// iterate over the data files and regenerate the entire site if they've changed
			$objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir."/_annotations/"), \RecursiveIteratorIterator::SELF_FIRST);
			
			// make sure dots are skipped
			$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
			
			foreach($objects as $name => $object) {
				
				$fileName = str_replace($sourceDir."/_annotations".DIRECTORY_SEPARATOR,"",$name);
				$mt = $object->getMTime();
				
				if (!isset($o->$fileName)) {
					$o->$fileName = $mt;
					$this->updateSite($fileName,"added");
				} else if ($o->$fileName != $mt) {
					$o->$fileName = $mt;
					$this->updateSite($fileName,"changed");
				}
				
			}
			
			// iterate over all of the other files in the source directory and move them if their modified time has changed
			if ($moveStatic) {
				
				$objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir."/"), \RecursiveIteratorIterator::SELF_FIRST);
				
				// make sure dots are skipped
				$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
				
				foreach($objects as $name => $object) {
					
					// clean-up the file name and make sure it's not one of the pattern lab files or to be ignored
					$fileName = str_replace($sourceDir.DIRECTORY_SEPARATOR,"",$name);
					if (($fileName[0] != "_") && (!in_array($object->getExtension(),$this->ie)) && (!in_array($object->getFilename(),$this->id))) {
						
						// catch directories that have the ignored dir in their path
						$ignoreDir = $this->ignoreDir($fileName);
						
						// check to see if it's a new directory
						if (!$ignoreDir && $object->isDir() && !isset($o->$fileName) && !is_dir($publicDir."/".$fileName)) {
							mkdir($publicDir."/".$fileName);
							$o->$fileName = "dir created"; // placeholder
							Console::writeLine($fileName."/ directory was created...");
						}
						
						// check to see if it's a new file or a file that has changed
						if (file_exists($name)) {
							
							$mt = $object->getMTime();
							if (!$ignoreDir && $object->isFile() && !isset($o->$fileName) && !file_exists($publicDir."/".$fileName)) {
								$o->$fileName = $mt;
								FileUtil::moveStaticFile($fileName,"added");
								if ($object->getExtension() == "css") {
									$this->updateSite($fileName,"changed",0); // make sure the site is updated for MQ reasons
								}
							} else if (!$ignoreDir && $object->isFile() && isset($o->$fileName) && ($o->$fileName != $mt)) {
								$o->$fileName = $mt;
								FileUtil::moveStaticFile($fileName,"changed");
								if ($object->getExtension() == "css") {
									$this->updateSite($fileName,"changed",0); // make sure the site is updated for MQ reasons
								}
							} else if (!isset($o->fileName)) {
								$o->$fileName = $mt;
							}
							
						} else {
							unset($o->$fileName);
						}
						
					}
					
				}
				
			}
			
			
			$c = true;
			
			// taking out the garbage. basically killing mustache after each run.
			if (gc_enabled()) gc_collect_cycles();
			
			// output anything the reload server might send our way
			if ($reload) {
				$output = fgets($fp, 100);
				if ($output != "\n") print $output;
			}
			
			// pause for .05 seconds to give the CPU a rest
			usleep(50000);
			
		}
		
		// close the auto-reload process, this shouldn't do anything
		fclose($fp);
		
	}
	
	/**
	* Updates the Pattern Lab Website and prints the appropriate message
	* @param  {String}       file name to included in the message
	* @param  {String}       a switch for decided which message isn't printed
	*
	* @return {String}       the final message
	*/
	private function updateSite($fileName,$message,$verbose = true) {
		
		Data::gather();
		PatternData::gather();
		Annotations::gather();
		
		$this->generateIndex();
		$this->generateStyleguide();
		$this->generateViewAllPages();
		$this->generatePatterns();
		$this->generateAnnotations();
		
		Util::updateChangeTime();
		
		if ($verbose) {
			if ($message == "added") {
				Console::writeLine($fileName." was added to Pattern Lab. Reload the website to see this change in the navigation...");
			} elseif ($message == "removed") {
				Console::writeLine($fileName." was removed from Pattern Lab. Reload the website to see this change reflected in the navigation...");
			} elseif ($message == "hidden") {
				Console::writeLine($fileName." was hidden from Pattern Lab. Reload the website to see this change reflected in the navigation...");
			} else {
				Console::writeLine($fileName." changed...");
			}
		}
	}
	
}
