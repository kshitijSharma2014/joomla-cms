<?php

class JUpdate {
	var $name;
	var $description;
	var $element;
	var $type;
	var $version;
	var $infourl;
	var $client;
	var $group;
	var $downloads;
	var $tags;
	var $maintainer;
	var $maintainerurl;
	var $category;
	var $relationships;
	var $targetplatform;
	
	var $_xml_parser;
	var $_stack = Array('base');
	
	/**
     * Gets the reference to the current direct parent
     *
     * @return object
     */
	function _getStackLocation()
    {
            return implode('->', $this->_stack);
    }
    
    function _getLastTag() {
    	return $this->_stack[count($this->_stack) - 1];
    }
	
	function _startElement($parser, $name, $attrs = Array()) {
		array_push($this->_stack, $name);
		$tag = $this->_getStackLocation();
		// reset the data
		eval('$this->'. $tag .'->_data = "";');
		//echo 'Opened: '; print_r($this->_stack); echo '<br />';
		//print_r($attrs); echo '<br />';
		switch($name) {
			case 'UPDATE':
				$this->_current_update = new stdClass();
				break;
			case 'UPDATES': // don't do anything
				break;
			default: // for everything else there's...the default!
				$name = strtolower($name);
				$this->_current_update->$name->_data = '';
				foreach($attrs as $key=>$data) {
					$key = strtolower($key);
					$this->_current_update->$name->$key = $data;	
				}
				break;
		}
	}
	
	function _endElement($parser, $name) {
		array_pop($this->_stack);
		//echo 'Closing: '. $name .'<br />';
		switch($name) {
			case 'UPDATE':
				$ver = new JVersion();
				$filter =& JFilterInput::getInstance();
				$product = strtolower($filter->clean($ver->PRODUCT, 'cmd'));
				if($product == $this->_current_update->targetplatform->name && $ver->RELEASE == $this->_current_update->targetplatform->version) {
					if(isset($this->_latest)) {
						if(version_compare($this->_current_update->version->_data, $this->_latest->version->_data, '>') == 1) {
							$this->_latest = $this->_current_update;
						}
					} else {
						$this->_latest = $this->_current_update;
					}
				}
				break;
			case 'UPDATES':
				// If the latest item is set then we transfer it to where we want to
				if(isset($this->_latest)) {
					foreach(get_object_vars($this->_latest) as $key=>$val) {
						$this->$key = $val;
					}
					unset($this->_latest);
					unset($this->_current_update);
				} else if(isset($this->_current_update)) {
					// the update might be for an older version of j!
					unset($this->_current_update);
				}
				break;
		}
	}
	
	function _characterData($parser, $data) {
		$tag = $this->_getLastTag();
		//if(!isset($this->$tag->_data)) $this->$tag->_data = ''; 
		//$this->$tag->_data .= $data;
		// Throw the data for this item together
		$tag = strtolower($tag);
		$this->_current_update->$tag->_data .= $data;
	}
	
	function loadFromXML($url) {
		if (!($fp = @fopen($url, "r"))) {
			// TODO: Add a 'mark bad' setting here somehow
		    JError::raiseWarning('101', JText::_('Update') .'::'. JText::_('Extension') .': '. JText::_('Could not open').' '. $url);
		    return false;
		}
		
		$this->xml_parser = xml_parser_create('');
		xml_set_object($this->xml_parser, $this);
		xml_set_element_handler($this->xml_parser, '_startElement', '_endElement');
		xml_set_character_data_handler($this->xml_parser, '_characterData');
	
		while ($data = fread($fp, 8192)) {
		    if (!xml_parse($this->xml_parser, $data, feof($fp))) {
		        die(sprintf("XML error: %s at line %d",
		                    xml_error_string(xml_get_error_code($this->xml_parser)),
		                    xml_get_current_line_number($this->xml_parser)));
		    }
		}
		xml_parser_free($this->xml_parser);
		return true;
	}
	
	function install() {
		global $mainframe;
		if(isset($this->downloadurl->_data)) {
			$url = $this->downloadurl->_data;
		} else {
			JError::raiseWarning('SOME_ERROR_CODE', JText::_('Invalid extension update'));
			return false;
		}
		
		jimport('joomla.installer.helper');
		$p_file = JInstallerHelper::downloadPackage($url);

		// Was the package downloaded?
		if (!$p_file) {
			JError::raiseWarning('SOME_ERROR_CODE', JText::_('Package download failed').': '. $url);
			return false;
		}

		$config =& JFactory::getConfig();
		$tmp_dest 	= $config->getValue('config.tmp_path');

		// Unpack the downloaded package file
		$package = JInstallerHelper::unpack($tmp_dest.DS.$p_file);
		
		// Get an installer instance
		$installer =& JInstaller::getInstance();

		// Install the package
		if (!$installer->install($package['dir'])) {
			// There was an error installing the package
			$msg = JText::sprintf('INSTALLEXT', JText::_($package['type']), JText::_('Error'));
			$result = false;
		} else {
			// Package installed sucessfully
			$msg = JText::sprintf('INSTALLEXT', JText::_($package['type']), JText::_('Success'));
			$result = true;
		}

		// Set some model state values
		$mainframe->enqueueMessage($msg);
		/*$this->setState('name', $installer->get('name'));
		$this->setState('result', $result);
		$this->setState('message', $installer->message);
		$this->setState('extension.message', $installer->get('extension.message'));*/

		// Cleanup the install files
		if (!is_file($package['packagefile'])) {
			$config =& JFactory::getConfig();
			$package['packagefile'] = $config->getValue('config.tmp_path').DS.$package['packagefile'];
		}

		JInstallerHelper::cleanupInstall($package['packagefile'], $package['extractdir']);

		return $result;
	}
}