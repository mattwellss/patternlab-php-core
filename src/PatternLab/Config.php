<?php

/*!
 * Config Class
 *
 * Copyright (c) 2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Configures Pattern Lab by checking config files and required files
 *
 */

namespace PatternLab;

use \PatternLab\Console;
use \PatternLab\FileUtil;
use \PatternLab\Timer;

class Config {
	
	protected static $options            = array();
	protected static $userConfig         = "config.ini";
	protected static $userConfigDir      = "";
	protected static $userConfigDirClean = "config";
	protected static $userConfigDirDash  = "_config";
	protected static $userConfigPath     = "";
	protected static $plConfigPath       = "../../config/config.ini.default";
	protected static $cleanValues        = array("ie","id","patternStates","styleGuideExcludes");
	protected static $dirAdded           = false;
	
	/**
	* Clean a given dir from the config file
	* @param  {String}       directory to be cleaned
	*
	* @return {String}       cleaned directory
	*/
	protected static function cleanDir($dir) {
		
		$dir = trim($dir);
		$dir = ($dir[0] == DIRECTORY_SEPARATOR) ? ltrim($dir, DIRECTORY_SEPARATOR) : $dir;
		$dir = ($dir[strlen($dir)-1] == DIRECTORY_SEPARATOR) ? rtrim($dir, DIRECTORY_SEPARATOR) : $dir;
		
		return $dir;
		
	}
	
	/**
	* Get the value associated with an option from the Config
	* @param  {String}       the name of the option to be checked
	* 
	* @return {String/Boolean} the value of the get or false if it wasn't found
	*/
	public static function getOption($optionName = "") {
		
		if (empty($optionName)) {
			return false;
		}
		
		if (array_key_exists($optionName,self::$options)) {
			return self::$options[$optionName];
		}
		
		return false;
		
	}
	
	/**
	* Adds the config options to a var to be accessed from the rest of the system
	* If it's an old config or no config exists this will update and generate it.
	* @param  {Boolean}       whether we should print out the status of the config being loaded
	*/
	public static function init($baseDir = "", $verbose = true) {
		
		// make sure a base dir was supplied
		if (empty($baseDir)) {
			Console::writeLine("<error>need a base directory to initialize the config class...</error>"); exit;
		}
		
		// normalize the baseDir
		$baseDir = FileUtil::normalizePath($baseDir);
		self::$options["baseDir"] = $baseDir;
		
		// can't add __DIR__ above so adding here
		if (!self::$dirAdded) {
			
			// set-up the paths
			self::$userConfigDirClean  = $baseDir.DIRECTORY_SEPARATOR.self::$userConfigDirClean;
			self::$userConfigDirDash   = $baseDir.DIRECTORY_SEPARATOR.self::$userConfigDirDash;
			self::$userConfigDir       = (is_dir(self::$userConfigDirDash)) ? self::$userConfigDirDash : self::$userConfigDirClean;
			self::$userConfigPath      = self::$userConfigDir.DIRECTORY_SEPARATOR.self::$userConfig;
			self::$plConfigPath        = __DIR__.DIRECTORY_SEPARATOR.self::$plConfigPath;
			self::$dirAdded            = true;
			
			// just in case the config directory doesn't exist at all
			if (!is_dir(self::$userConfigDir)) {
				mkdir(self::$userConfigDir);
			}
			
		}
		
		// make sure migrate doesn't happen by default
		$migrate     = false;
		$diffVersion = false;
		
		// double-check the default config file exists
		if (!file_exists(self::$plConfigPath)) {
			Console::writeLine("<error>make sure config.ini.default exists before trying to have Pattern Lab build the config.ini file automagically...</error>"); exit;
		}
		
		// set the default config using the pattern lab config
		if (!self::$options = @parse_ini_file(self::$plConfigPath)) {
			Console::writeLine("<error>Config parse error in</error> <path>".self::$plConfigPath."</path><error>...</error>"); 
			exit;
		}
		
		// make sure these are copied
		$defaultOptions = self::$options;
		
		// check to see if the user config exists, if not create it
		if ($verbose) {
			Console::writeLine("configuring pattern lab...");
		}
		
		if (!file_exists(self::$userConfigPath)) {
			$migrate = true;
		} else {
			if (!self::$options = @parse_ini_file(self::$userConfigPath)) {
				Console::writeLine("<error>Config parse error in</error> <path>".self::$userConfigPath."</path><error>...</error>"); 
				exit;
			}
		}
		
		// compare version numbers
		$diffVersion = (self::$options["v"] != $defaultOptions["v"]) ? true : false;
		
		// run an upgrade and migrations if necessary
		if ($migrate || $diffVersion) {
			if ($verbose) {
				Console::writeLine("<info>upgrading your version of pattern lab...</info>");
			}
			if ($migrate) {
				if (!@copy(self::$plConfigPath, self::$userConfigPath)) {
					Console::writeLine("<error>make sure that Pattern Lab can write a new config to ".self::$userConfigPath."...</error>");
					exit;
				}
			} else {
				self::$options = self::writeNewConfigFile(self::$options,$defaultOptions);
			}
		}
		
		// making sure the config isn't empty
		if (empty(self::$options) && $verbose) {
			Console::writeLine("<error>a set of configuration options is required to use Pattern Lab...");
			exit;
		}
		
		// set-up the various dirs
		$baseFull                          = $baseDir.DIRECTORY_SEPARATOR;
		self::$options["baseDir"]          = $baseDir;
		self::$options["coreDir"]          = (is_dir($baseFull."_core")) ? $baseFull."_core" : $baseFull."core";
		self::$options["exportDir"]        = isset(self::$options["exportDir"])   ? $baseFull.self::cleanDir(self::$options["exportDir"])   : $baseFull."exports";
		self::$options["packagesDir"]      = isset(self::$options["packagesDir"]) ? $baseFull.self::cleanDir(self::$options["packagesDir"]) : $baseFull."packages";
		self::$options["publicDir"]        = isset(self::$options["publicDir"])   ? $baseFull.self::cleanDir(self::$options["publicDir"])   : $baseFull."public";
		self::$options["scriptsDir"]       = isset(self::$options["scriptsDir"])  ? $baseFull.self::cleanDir(self::$options["scriptsDir"])  : $baseFull."scripts";
		self::$options["sourceDir"]        = isset(self::$options["sourceDir"])   ? $baseFull.self::cleanDir(self::$options["sourceDir"])   : $baseFull."source";
		self::$options["dataDir"]          = self::$options["sourceDir"]."/_data";
		self::$options["patternExportDir"] = self::$options["exportDir"]."/patterns";
		self::$options["patternPublicDir"] = self::$options["publicDir"]."/patterns";
		self::$options["patternSourceDir"] = self::$options["sourceDir"]."/_patterns";
		
		// populate some standard variables out of the config
		foreach (self::$options as $key => $value) {
			
			// if the variables are array-like make sure the properties are validated/trimmed/lowercased before saving
			if (in_array($key,self::$cleanValues)) {
				$values = explode(",",$value);
				array_walk($values,'PatternLab\Util::trim');
				self::$options[$key] = $values;
			} else if ($key == "ishControlsHide") {
				self::$options[$key] = new \stdClass();
				$class = self::$options[$key];
				if ($value != "") {
					$values = explode(",",$value);
					foreach($values as $value2) {
						$value2 = trim($value2);
						$class->$value2 = true;
					}
				}
			}
			
		}
		
		// set the cacheBuster
		self::$options["cacheBuster"] = (self::$options["cacheBusterOn"] == "false") ? 0 : time();
		
		// provide the default for enable CSS. performance hog so it should be run infrequently
		self::$options["enableCSS"] = false;
		
		// which of these should be exposed in the front-end?
		self::$options["exposedOptions"] = array();
		self::setExposedOption("cacheBuster");
		self::setExposedOption("ishFontSize");
		self::setExposedOption("ishMaximum");
		self::setExposedOption("ishMinimum");
		
	}
	
	/**
	* Add an option and associated value to the base Config
	* @param  {String}       the name of the option to be added
	* @param  {String}       the value of the option to be added
	* 
	* @return {Boolean}      whether the set was successful
	*/
	public static function setOption($optionName = "", $optionValue = "") {
		
		if (empty($optionName) || empty($optionValue)) {
			return false;
		}
		
		if (!array_key_exists($optionName,self::$options)) {
			self::$options[$optionName] = $optionValue;
			return true;
		}
		
		return false;
		
	}
	
	/**
	* Add an option to the exposedOptions array so it can be exposed on the front-end
	* @param  {String}       the name of the option to be added to the exposedOption arrays
	* 
	* @return {Boolean}      whether the set was successful
	*/
	public static function setExposedOption($optionName = "") {
		
		if (!empty($optionName) && isset(self::$options[$optionName])) {
			if (!in_array($optionName,self::$options["exposedOptions"])) {
				self::$options["exposedOptions"][] = $optionName;
			}
			return true;
		}
		
		return false;
		
	}
	
	/**
	* Update a single config option based on a change in composer.json
	* @param  {String}       the name of the option to be changed
	* @param  {String}       the new value of the option to be changed
	*/
	public static function updateConfigOption($optionName,$optionValue) {
		
		if (strpos($optionValue,"<prompt>") !== false) {
			
			// if this is a prompt always write out the query
			$output = str_replace("</prompt>","",str_replace("<prompt>","",$optionValue))."<nophpeol>";
			
			$stdin = fopen("php://stdin", "r");
			Console::writeLine($output);
			$input = strtolower(trim(fgets($stdin)));
			fclose($stdin);
			
			self::writeUpdateConfigOption($optionName,$input);
			Console::writeLine("<ok>config option ".$optionName." updated...</ok>", false, true);
			
		} else if (!isset(self::$options[$optionName]) || (self::$options["overrideConfig"] == "a")) {
			
			// if the option isn't set or the config is always to override update the config
			self::writeUpdateConfigOption($optionName,$optionValue);
			
		} else if (self::$options["overrideConfig"] == "q") {
			
			// if the option is to query the user when the option is set do so
			$output = "<info>update the config option </info><desc>".$optionName."</desc><info> with the value </info><desc>".$optionValue."</desc><info>?</info> <options>Y/n</options><info> > </info><nophpeol>";
			
			$stdin = fopen("php://stdin", "r");
			Console::writeLine($output);
			$input = strtolower(trim(fgets($stdin)));
			fclose($stdin);
			
			if (!$prompt && ($input == "y")) {
				self::writeUpdateConfigOption($optionName,$optionValue);
				Console::writeLine("<ok>config option ".$optionName." updated...</ok>", false, true);
			} else {
				Console::writeLine("<warning>config option </warning><desc>".$optionName."</desc><warning> not  updated...</warning>", false, true);
			}
			
		}
		
	}
	
	/**
	* Update an option and associated value to the base Config
	* @param  {String}       the name of the option to be updated
	* @param  {String}       the value of the option to be updated
	* 
	* @return {Boolean}      whether the update was successful
	*/
	public static function updateOption($optionName = "", $optionValue = "") {
		
		if (empty($optionName) || empty($optionValue)) {
			return false;
		}
		
		if (array_key_exists($optionName,self::$options)) {
			self::$options[$optionName] = $optionValue;
			return true;
		}
		
		return false;
		
	}
	
	/**
	* Write out the new config option value
	* @param  {String}       the name of the option to be changed
	* @param  {String}       the new value of the option to be changed
	*/
	protected static function writeUpdateConfigOption($optionName,$optionValue) {
		
		$configOutput = "";
		$options      = parse_ini_file(self::$userConfigPath);
		$options[$optionName] = $optionValue;
		
		foreach ($options as $key => $value) {
			$configOutput .= $key." = \"".$value."\"\n";
		}
		
		// write out the new config file
		file_put_contents(self::$userConfigPath,$configOutput);
		
	}
	
	/**
	* Use the default config as a base and update it with old config options. Write out a new user config.
	* @param  {Array}        the old configuration file options
	* @param  {Array}        the default configuration file options
	*
	* @return {Array}        the new configuration
	*/
	protected static function writeNewConfigFile($oldOptions,$defaultOptions) {
		
		// iterate over the old config and replace values in the new config
		foreach ($oldOptions as $key => $value) {
			if ($key != "v") {
				$defaultOptions[$key] = $value;
			}
		}
		
		// create the output data
		$configOutput = "";
		foreach ($defaultOptions as $key => $value) {
			$configOutput .= $key." = \"".$value."\"\n";
		}
		
		// write out the new config file
		file_put_contents(self::$userConfigPath,$configOutput);
		
		return $defaultOptions;
		
	}
	
}
