<?php

/*!
 * Builder Class
 *
 * Copyright (c) 2013-2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Holds most of the "generate" functions used in the the Generator and Watcher class
 *
 */

namespace PatternLab;

use \PatternLab\Annotations;
use \PatternLab\Config;
use \PatternLab\Data;
use \PatternLab\Parsers\Documentation;
use \PatternLab\PatternData\Exporters\NavItemsExporter;
use \PatternLab\PatternData\Exporters\PatternPartialsExporter;
use \PatternLab\PatternData\Exporters\PatternPathDestsExporter;
use \PatternLab\PatternData\Exporters\ViewAllPathsExporter;
use \PatternLab\PatternEngine;
use \PatternLab\Render;
use \PatternLab\Template;
use \PatternLab\Timer;

class Builder {
	
	/**
	* When initializing the Builder class make sure the template helper is set-up
	*/
	public function __construct() {
		
		//$this->patternCSS   = array();
		
		// set-up the pattern engine
		PatternEngine::init();
		
		// set-up the various attributes for rendering templates
		Template::init();
		
	}
	
	/**
	* Finds Media Queries in CSS files in the source/css/ dir
	*
	* @return {Array}        an array of the appropriate MQs
	*/
	protected function gatherMQs() {
		
		$mqs = array();
		
		// iterate over all of the other files in the source directory
		$directoryIterator = new \RecursiveDirectoryIterator(Config::getOption("sourceDir"));
		$objects           = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
		
		// make sure dots are skipped
		$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
		
		foreach ($objects as $name => $object) {
			
			if ($object->isFile() && ($object->getExtension() == "css")) {
				
				$data = file_get_contents($object->getPathname());
				preg_match_all("/(min|max)-width:([ ]+)?(([0-9]{1,5})(\.[0-9]{1,20}|)(px|em))/",$data,$matches);
				foreach ($matches[3] as $match) {
					if (!in_array($match,$mqs)) {
						$mqs[] = $match;
					}
				}
				
			}
			
		}
		
		usort($mqs, "strnatcmp");
		
		return $mqs;
		
	}
	
	/**
	* Generates the annotations js file
	*/
	protected function generateAnnotations() {
		
		// default var
		$publicDir = Config::getOption("publicDir");
		
		// encode the content so it can be written out
		$json      = json_encode(Annotations::get());
		
		// make sure annotations/ exists
		if (!is_dir($publicDir."/annotations")) {
			mkdir($publicDir."/annotations");
		}
		
		// write out the new annotations.js file
		file_put_contents($publicDir."/annotations/annotations.js","var comments = ".$json.";");
		
	}
	
	/**
	* Generates the data that powers the index page
	*/
	protected function generateIndex() {
		
		// default var
		$dataDir = Config::getOption("publicDir")."/styleguide/data";
		
		// double-check that the data directory exists
		if (!is_dir($dataDir)) {
			mkdir($dataDir);
		}
		
		$output = "";
		
		// load and write out the config options
		$config                         = array();
		$exposedOptions                 = Config::getOption("exposedOptions");
		foreach ($exposedOptions as $exposedOption) {
			$config[$exposedOption]     = Config::getOption($exposedOption);
		}
		$output     .= "var config = ".json_encode($config).";";
		
		// load the ish Controls
		$ishControls                    = array();
		$ishControls["ishControlsHide"] = Config::getOption("ishControlsHide");
		$ishControls["mqs"]             = $this->gatherMQs();
		$output      .= "var ishControls = ".json_encode($ishControls).";";
		
		// load and write out the items for the navigation
		$niExporter   = new NavItemsExporter();
		$navItems     = $niExporter->run();
		$output      .= "var navItems = ".json_encode($navItems).";";
		
		// load and write out the items for the pattern paths
		$patternPaths = array();
		$ppdExporter  = new PatternPathDestsExporter();
		$patternPaths = $ppdExporter->run();
		$output      .= "var patternPaths = ".json_encode($patternPaths).";";
		
		// load and write out the items for the view all paths
		$viewAllPaths = array();
		$vapExporter  = new ViewAllPathsExporter();
		$viewAllPaths = $vapExporter->run($navItems);
		$output      .= "var viewAllPaths = ".json_encode($viewAllPaths).";";
		
		// write out the data
		file_put_contents($dataDir."/patternlab-data.js",$output);
		
	}
	
	/**
	* Generates all of the patterns and puts them in the public directory
	* @param   {Array}     various options that might affect the export. primarily the location.
	*/
	protected function generatePatterns($options = array()) {
		
		// set-up common vars
		$exportFiles      = (isset($options["exportFiles"]) && $options["exportFiles"]);
		$exportDir        = Config::getOption("exportDir");
		$patternPublicDir = !$exportFiles ? Config::getOption("patternPublicDir") : Config::getOption("patternExportDir");
		$patternSourceDir = Config::getOption("patternSourceDir");
		$patternExtension = Config::getOption("patternExtension");
		
		
		// make sure the export dir exists
		if ($exportFiles && !is_dir($exportDir)) {
			mkdir($exportDir);
		}
		
		// make sure patterns exists
		if (!is_dir($patternPublicDir)) {
			mkdir($patternPublicDir);
		}
		
		// loop over the pattern data store to render the individual patterns
		$store = PatternData::get();
		foreach ($store as $patternStoreKey => $patternStoreData) {
			
			if (($patternStoreData["category"] == "pattern") && (!$patternStoreData["hidden"])) {
				
				$path          = $patternStoreData["pathDash"];
				$pathName      = (isset($patternStoreData["pseudo"])) ? $patternStoreData["pathOrig"] : $patternStoreData["pathName"];
				
				// modify the pattern mark-up
				$markup        = $patternStoreData["code"];
				$markupEncoded = htmlentities($markup);
				$markupFull    = $patternStoreData["header"].$markup.$patternStoreData["footer"];
				$markupEngine  = htmlentities(file_get_contents($patternSourceDir."/".$pathName.".".$patternExtension));
				
				// if the pattern directory doesn't exist create it
				if (!is_dir($patternPublicDir."/".$path)) {
					mkdir($patternPublicDir."/".$path);
				}
				
				// write out the various pattern files
				file_put_contents($patternPublicDir."/".$path."/".$path.".html",$markupFull);
				if (!$exportFiles) {
					file_put_contents($patternPublicDir."/".$path."/".$path.".escaped.html",$markupEncoded);
					file_put_contents($patternPublicDir."/".$path."/".$path.".".$patternExtension,$markupEngine);
				}
				/*
				Not being used and should be moved to a plug-in
				if (Config::$options["enableCSS"] && isset($this->patternCSS[$p])) {
					file_put_contents($patternPublicDir.$path."/".$path.".css",htmlentities($this->patternCSS[$p]));
				}
				*/
				
			}
			
		}
		
	}
	
	/**
	* Generates the style guide view
	*/
	protected function generateStyleguide() {
		
		// default var
		$publicDir = Config::getOption("publicDir");
		
		// check directories i need
		if (!is_dir($publicDir."/styleguide/")) {
			mkdir($publicDir."/styleguide/");
		}
		
		if (!is_dir($publicDir."/styleguide/html/")) {
			mkdir($publicDir."/styleguide/html/");
		}
			
		// grab the partials into a data object for the style guide
		$ppExporter                 = new PatternPartialsExporter();
		$partials                   = $ppExporter->run();
		
		// add the pattern data so it can be exported
		$patternData = array();
		
		// add the pattern lab specific mark-up
		$partials["patternLabHead"] = Render::Header(Template::getHTMLHead(),array("cacheBuster" => $partials["cacheBuster"]));
		$partials["patternLabFoot"] = Render::Footer(Template::getHTMLFoot(),array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
		
		$header                     = Render::Header(Template::getPatternHead(),$partials);
		$code                       = Template::getFilesystemLoader()->render("viewall",$partials);
		$footer                     = Render::Footer(Template::getPatternFoot(),$partials);
		
		$styleGuidePage             = $header.$code.$footer;
		
		file_put_contents($publicDir."/styleguide/html/styleguide.html",$styleGuidePage);
		
	}
	
	/**
	* Generates the view all pages
	*/
	protected function generateViewAllPages() {
		
		// default vars
		$patternPublicDir = Config::getOption("patternPublicDir");
		$htmlHead         = Template::getHTMLHead();
		$htmlFoot         = Template::getHTMLFoot();
		$patternHead      = Template::getPatternHead();
		$patternFoot      = Template::getPatternFoot();
		$filesystemLoader = Template::getFilesystemLoader();
		
		// make sure the pattern dir exists
		if (!is_dir($patternPublicDir)) {
			mkdir($patternPublicDir);
		}
		
		// add view all to each list
		$store = PatternData::get();
		foreach ($store as $patternStoreKey => $patternStoreData) {
			
			if ($patternStoreData["category"] == "patternSubtype") {
				
				// grab the partials into a data object for the style guide
				$ppExporter  = new PatternPartialsExporter();
				$partials    = $ppExporter->run($patternStoreData["type"],$patternStoreData["name"]);
				
				if (!empty($partials["partials"])) {
					
					// add the pattern data so it can be exported
					$patternData = array();
					$patternData["patternPartial"] = "viewall-".$patternStoreData["typeDash"]."-".$patternStoreData["nameDash"];
					
					// add the pattern lab specific mark-up
					$partials["patternLabHead"] = Render::Header($htmlHead,array("cacheBuster" => $partials["cacheBuster"]));
					$partials["patternLabFoot"] = Render::Footer($htmlFoot,array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
					
					// render the parts and join them
					$header      = Render::Header($patternHead,$partials);
					$code        = $filesystemLoader->render("viewall",$partials);
					$footer      = Render::Footer($patternFoot,$partials);
					$viewAllPage = $header.$code.$footer;
					
					// if the pattern directory doesn't exist create it
					$patternPath = $patternStoreData["pathDash"];
					if (!is_dir($patternPublicDir."/".$patternPath)) {
						mkdir($patternPublicDir."/".$patternPath);
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					} else {
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					}
					
				}
				
			} else if (($patternStoreData["category"] == "patternType") && PatternData::hasPatternSubtype($patternStoreData["nameDash"])) {
				
				// grab the partials into a data object for the style guide
				$ppExporter  = new PatternPartialsExporter();
				$partials    = $ppExporter->run($patternStoreData["name"]);
				
				if (!empty($partials["partials"])) {
					
					// add the pattern data so it can be exported
					$patternData = array();
					$patternData["patternPartial"] = "viewall-".$patternStoreData["nameDash"]."-all";
					
					// add the pattern lab specific mark-up
					$partials["patternLabHead"] = Render::Header($htmlHead,array("cacheBuster" => $partials["cacheBuster"]));
					$partials["patternLabFoot"] = Render::Footer($htmlFoot,array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
					
					// render the parts and join them
					$header      = Render::Header($patternHead,$partials);
					$code        = $filesystemLoader->render("viewall",$partials);
					$footer      = Render::Footer($patternFoot,$partials);
					$viewAllPage = $header.$code.$footer;
					
					// if the pattern directory doesn't exist create it
					$patternPath = $patternStoreData["pathDash"];
					if (!is_dir($patternPublicDir."/".$patternPath)) {
						mkdir($patternPublicDir."/".$patternPath);
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					} else {
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					}
					
				}
				
			}
			
		}
		
	}
	
	/**
	* Loads the CSS from source/css/ into CSS Rule Saver to be used for code view
	* Will eventually get pushed elsewhere
	*/
	protected function initializeCSSRuleSaver() {
		
		$loader = new \SplClassLoader('CSSRuleSaver', __DIR__.'/../../lib');
		$loader->register();
		
		$this->cssRuleSaver = new \CSSRuleSaver\CSSRuleSaver;
		
		foreach(glob(Config::getOption("sourceDir")."/css/*.css") as $filename) {
			$this->cssRuleSaver->loadCSS($filename);
		}
		
	}
	
}
