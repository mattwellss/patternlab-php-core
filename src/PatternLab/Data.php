<?php

/*!
 * Data Class
 *
 * Copyright (c) 2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Acts as the overall data store for Pattern Lab. Takes in data found in JSON and YAML files.
 *
 */

namespace PatternLab;

use \PatternLab\Config;
use \PatternLab\Console;
use \PatternLab\Dispatcher;
use \PatternLab\Timer;
use \Symfony\Component\Yaml\Exception\ParseException;
use \Symfony\Component\Yaml\Yaml;

class Data {
	
	protected static $store        = array();
	protected static $reservedKeys = array("listItems","cacheBuster","link","patternSpecific","patternLabHead","patternLabFoot");
	
	/**
	* Gather data from any JSON and YAML files in source/_data
	*
	* Reserved attributes:
	*    - Data::$store["listItems"] : listItems from listitems.json, duplicated into separate arrays for Data::$store["listItems"]["one"], Data::$store["listItems"]["two"]... etc.
	*    - Data::$store["link"] : the links to each pattern
	*    - Data::$store["cacheBuster"] : the cache buster value to be appended to URLs
	*    - Data::$store["patternSpecific"] : holds attributes from the pattern-specific data files
	*
	* @return {Array}        populates Data::$store
	*/
	public static function gather($options = array()) {
		
		// default vars
		$found         = false;
		$dataJSON      = array();
		$dataYAML      = array();
		$listItemsJSON = array();
		$listItemsYAML = array();
		$sourceDir     = Config::getOption("sourceDir");
		
		// iterate over all of the other files in the source directory
		if (!is_dir($sourceDir."/_data/")) {
			Console::writeLine("<path>_data/</path><warning> doesn't exist so you won't have dynamic data...</warning>");
			exit;
		}
		$directoryIterator = new \RecursiveDirectoryIterator($sourceDir."/_data/");
		$objects           = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
		
		// make sure dots are skipped
		$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
		
		foreach ($objects as $name => $object) {
			
			$ext           = $object->getExtension();
			$data          = array();
			$fileName      = $object->getFilename();
			$hidden        = ($fileName[0] == "_");
			$isFile        = $object->isFile();
			$isListItems   = strpos("listitems",$fileName);
			$pathName      = $object->getPathname();
			$pathNameClean = str_replace($sourceDir."/","",$pathName);
			
			if ($isFile && !$hidden && (($ext == "json") || ($ext == "yaml"))) {
				
				if ($isListItems === false) {
					
					if ($ext == "json") {
						
						$file = file_get_contents($pathName);
						$data = json_decode($file,true);
						if ($jsonErrorMessage = JSON::hasError()) {
							JSON::lastErrorMsg($pathNameClean,$jsonErrorMessage,$data);
						}
						
					} else if ($ext == "yaml") {
						
						$file = file_get_contents($pathName);
						
						try {
							$data = YAML::parse($file);
						} catch (ParseException $e) {
							printf("unable to parse ".$pathNameClean.": %s..\n", $e->getMessage());
						}
						
						// single line of text won't throw a YAML error. returns as string
						if (gettype($data) == "string") {
							$data = array();
						}
						
					}
					
					self::$store = array_replace_recursive(self::$store,$data);
					
				} else if ($isListItems !== false) {
					
					$data = ($ext == "json") ? self::getListItems("data/listitems.json") : self::getListItems("data/listitems.yaml","yaml");
					
					if (!isset(self::$store["listItems"])) {
						self::$store["listItems"] = array();
					}
					
					self::$store["listItems"] = array_replace_recursive(self::$store["listItems"],$data);
					
				}
				
			}
			
		}
		
		if (is_array(self::$store)) {
			foreach (self::$reservedKeys as $reservedKey) {
				if (array_key_exists($reservedKey,self::$store)) {
					Console::writeLine("<warning>\"".$reservedKey."\" is a reserved key in Pattern Lab. the data using that key will be overwritten. please choose a new key...</warning>");
				}
			}
		}
		
		self::$store["cacheBuster"]     = Config::getOption("cacheBuster");
		self::$store["link"]            = array();
		self::$store["patternSpecific"] = array();
		
		Dispatcher::getInstance()->dispatch("data.dataLoadEnd");
		
	}
	
	/**
	* Grab a copy of the $store
	*
	* @return {Array}        the store
	*/
	public static function get() {
		return self::$store;
	}
	
	/**
	* Generate the listItems array
	* @param  {String}       the filename for the pattern to be parsed
	* @param  {String}       the extension so that a flag switch can be used to parse data
	*
	* @return {Array}        the final set of list items
	*/
	protected static function getListItems($filepath,$ext = "json") {
		
		// default vars
		$sourceDir     = Config::getOption("sourceDir");
		$listItems     = array();
		$listItemsData = array();
		
		// add list item data, makes 'listItems' a reserved word
		if (file_exists($sourceDir."/".$filepath)) {
			
			$file = file_get_contents($sourceDir."/".$filepath);
			
			if ($ext == "json") {
				$listItemsData = json_decode($file, true);
				if ($jsonErrorMessage = JSON::hasError()) {
					JSON::lastErrorMsg($filepath,$jsonErrorMessage,$listItems);
				}
			} else {
				
				try {
					$listItemsData = YAML::parse($file);
				} catch (ParseException $e) {
					printf("unable to parse ".$pathNameClean.": %s..\n", $e->getMessage());
				}
				
				// single line of text won't throw a YAML error. returns as string
				if (gettype($listItemsData) == "string") {
					$listItemsData = array();
				}
				
			}
			
			
			$numbers = array("one","two","three","four","five","six","seven","eight","nine","ten","eleven","twelve");
			
			$i = 0;
			$k = 1;
			$c = count($listItemsData)+1;
			
			while ($k < $c) {
				
				shuffle($listItemsData);
				$itemsArray = array();
				
				while ($i < $k) {
					$itemsArray[] = $listItemsData[$i];
					$i++;
				}
				
				$listItems[$numbers[$k-1]] = $itemsArray;
				
				$i = 0;
				$k++;
				
			}
			
		}
		
		return $listItems;
		
	}
	
	/**
	* Return the value for an option
	*
	* @return {String}        the value of the option requested
	*/
	public static function getOption($optionName) {
		
		if (isset(self::$store[$optionName])) {
			return self::$store[$optionName];
		}
		
		return false;
		
	}
	
	/**
	* Get the final data array specifically for a pattern
	* @param  {String}       the filename for the pattern to be parsed
	* @param  {Array}        any extra data that should be added to the pattern specific data that's being returned
	*
	* @return {Array}        the final set of list items
	*/
	public static function getPatternSpecificData($patternPartial,$extraData = array()) {
		
		// if there is pattern-specific data make sure to override the default in $this->d
		$d = self::get();
		
		if (isset($d["patternSpecific"]) && array_key_exists($patternPartial,$d["patternSpecific"])) {
			
			if (!empty($d["patternSpecific"][$patternPartial]["data"])) {
				$d = array_replace_recursive($d, $d["patternSpecific"][$patternPartial]["data"]);
			}
			
			if (!empty($d["patternSpecific"][$patternPartial]["listItems"])) {
				
				$numbers = array("one","two","three","four","five","six","seven","eight","nine","ten","eleven","twelve");
				
				$k = 0;
				$c = count($d["patternSpecific"][$patternPartial]["listItems"]);
				
				while ($k < $c) {
					$section = $numbers[$k];
					$d["listItems"][$section] = array_replace_recursive( $d["listItems"][$section], $d["patternSpecific"][$patternPartial]["listItems"][$section]);
					$k++;
				}
				
			}
			
		}
		
		if (!empty($extraData)) {
			$d = array_replace_recursive($d, $extraData);
		}
		
		unset($d["patternSpecific"]);
		
		return $d;
		
	}
	
	/**
	* Initialize a pattern specific data store under the patternSpecific option
	* @param  {String}       the pattern to create an array for
	*/
	public static function initPattern($optionName) {
		
		if (!isset(self::$store["patternSpecific"])) {
			self::$store["patternSpecific"] = array();
		}
		
		if ((!isset(self::$store["patternSpecific"][$optionName])) || (!is_array(self::$store["patternSpecific"][$optionName]))) {
			self::$store["patternSpecific"][$optionName] = array();
		}
		
	}
	
	/**
	* Print out the data var. For debugging purposes
	*
	* @return {String}       the formatted version of the d object
	*/
	public static function printData() {
		print_r(self::$store);
	}
	
	/**
	* Set an option on a sub element of the data array
	* @param  {String}       name of the option
	* @param  {String}       value for the option
	*/
	public static function setOptionLink($optionName,$optionValue) {
		
		if (!isset(self::$store["link"])) {
			self::$store["link"] = array();
		}
		
		self::$store["link"][$optionName] = $optionValue;
		
	}
	
	/**
	* Set the pattern data option
	* @param  {String}       name of the pattern
	* @param  {String}       value for the pattern's data attribute
	*/
	public static function setPatternData($optionName,$optionValue) {
		
		if (isset(self::$store["patternSpecific"][$optionName])) {
			self::$store["patternSpecific"][$optionName]["data"] = $optionValue;
		}
		
		return false;
		
	}
	
	/**
	* Set the pattern listitems option
	* @param  {String}       name of the pattern
	* @param  {String}       value for the pattern's listItem attribute
	*/
	public static function setPatternListItems($optionName,$optionValue) {
		
		if (isset(self::$store["patternSpecific"][$optionName])) {
			self::$store["patternSpecific"][$optionName]["listItems"] = $optionValue;
		}
		
		return false;
		
	}
	
}