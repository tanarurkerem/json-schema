<?php

class JsSchemaUndefined {}
class JsSchema {
  
  static $errors = array();
  
  static public function validate($instance, $schema = null) {
    return self::_validate($instance,$schema,false);
  }
  
  static function _validate($instance,$schema = null,$_changing) {
  	// verify passed schema
    if ($schema) {
	  self::checkProp($instance,$schema,'','',$_changing);
    }
    // verify "inline" schema
    $propName = '$schema';
	if (!$_changing && isset($instance->$propName)) {
	  self::checkProp($instance,$instance->$propName,'','',$_changing);
	}
	// show results
	$obj = new stdClass();
	$obj->valid = ! ((boolean)count(self::$errors));
	$obj->errors = self::$errors;
	return $obj;
  }
  
  static function incrementPath($path,$i) {
    if($path) {
	  if(is_int($i)) {
	    $path .= '['.$i.']';
	  }
	  elseif($i = '') {
	    $path .= '';
	  }
	  else {
	    $path .= '.'.$i;
	  }
    }
    else {
      $path = $i;
    }
    return $path;
  }
  
  static function checkArray($value,$schema,$path,$i,$_changing) {
    //verify items
    if(isset($schema->items)) {
      //tuple typing
      if(is_array($schema->items)) {
        foreach($value as $k=>$v) {
          if(array_key_exists($k,$schema->items)) {
            self::checkProp($v,$schema->items[$k],$path,$k,$_changing);
          }
          else {
            // aditional array properties
            if(array_key_exists('additionalProperties',$schema)) { 
              if($schema->additionalProperties === false) { 	
                self::adderror(
                  $path,
                  'The item '.$i.'['.$k.'] is not defined in the objTypeDef and the objTypeDef does not allow additional properties'
                );
              }
              else {
                self::checkProp($v,$schema->additionalProperties,$path,$k,$_changing);	
              }
            }
          }
        }//foreach($value as $k=>$v) {
        // treat when we have more schema definitions than values
        for($k = count($value); $k < count($schema->items); $k++) {
          self::checkProp( 
            new JsSchemaUndefined(),
            $schema->items[$k], $path, $k, $_changing
          );
        }
      }
      // just one type definition for the whole array
      else {
        foreach($value as $k=>$v) {
          self::checkProp($v,$schema->items,$path,$k,$_changing);
        }
      }
    }
    // verify number of array items
    if(isset($schema->minItems) && count($value) < $schema->minItems) {
      self::adderror($path,"There must be a minimum of " . $schema->minItems . " in the array");
    }
    if(isset($schema->maxItems) && count($value) > $schema->maxItems) {
      self::adderror($path,"There must be a maximum of " . $schema->maxItems . " in the array");
    }
  }
  
  static function checkProp($value, $schema, $path, $i = '', $_changing = false) {
    if (!is_object($schema)) {
    	return;
    }
    $path = self::incrementPath($path,$i);
    // verify readonly
    if($_changing && $schema.readonly) {
      self::adderror($path,'is a readonly field, it can not be changed');
    }
    // I think a schema cant be an array, only the items property
    /*if(is_array($schema)) {
      if(!is_array($value)) {
      	return array(array('property'=>$path,'message'=>'An array tuple is required'));
      }
      for($a = 0; $a < count($schema); $a++) {
      	self::$errors = array_merge(
      	  self::$errors,
      	  self::checkProp($value->$a,$schema->$a,$path,$i,$_changing)
      	);
      	return self::$errors;
      }
    }*/
    // if it extends another schema, it must pass that schema as well
    if(isset($schema->extends)) {
      self::checkProp($value,$schema->extends,$path,$i,$_changing);
    }
    // verify optional values
    if (is_object($value) && $value instanceOf JsSchemaUndefined) {
  	  if ( isset($schema->optional) ? !$schema->optional : true) {
	    self::adderror($path,"is missing and it is not optional");
	  }
    } 
    // normal verifications
    else {
      self::$errors = array_merge(
        self::$errors,
        self::checkType( isset($schema->type) ? $schema->type : null , $value, $path)
      );
    }
    if(array_key_exists('disallow',$schema)) {
      $errorsBeforeDisallowCheck = self::$errors;
      Dbg::object($errorsBeforeDisallowCheck,'$errorsBeforeDisallowCheck');
      $response = self::checkType($schema->disallow, $value, $path);
      Dbg::object(count(self::$errors),'after errors');
      if(
        ( count($errorsBeforeDisallowCheck) == count(self::$errors) )  &&
        !count($response)
      ) {
        self::adderror($path," disallowed value was matched");
      }
      else {
        self::$errors = $errorsBeforeDisallowCheck;
      }
    }
    //verify the itens on an array and min and max number of items.
    if(is_array($value)) {
      self::checkArray($value,$schema,$path,$i,$_changing);
    }
    ############ verificar!
    elseif(isset($schema->properties) && is_object($value)) {
      self::checkObj(
        $value,
        $schema->properties,
        $path,
        isset($schema->additionalProperties) ? $schema->additionalProperties : null,
        $_changing 
      );
    }
    // verify a regex pattern
    if( isset($schema->pattern) && is_string($value) && !preg_match('/'.$schema->pattern.'/',$value)) {
      self::adderror($path,"does not match the regex pattern " . $schema->pattern);
    }  
    // verify maxLength, minLength, maximum and minimum values
    if( isset($schema->maxLength) && is_string($value) && (strlen($value) > $schema->maxLength)) {
      self::adderror($path,"may be at most" . $schema->maxLength . " characters long");
    }
    if( isset($schema->minLength) && is_string($value) && strlen($value) < $schema->minLength) {
      self::adderror($path,"must be at least " . $schema->minLength . " characters long");
    }
    if( isset($schema->minimum) && gettype($value) == gettype($schema->minimum) && $value < $schema->minimum) {
      self::adderror($path,"must have a minimum value of " . $schema->minimum);
    }
    if( isset($schema->maximum) && gettype($value) == gettype($schema->maximum) && $value > $schema->maximum) {
      self::adderror($path,"must have a maximum value of " . $schema->maximum);
    }
    // verify enum values
    if(isset($schema->enum)) {
      $found = false;
      foreach($schema->enum as $possibleValue) {
        if($possibleValue == $value) {
          $found = true;
          break;
        }
      }
      if(!$found) {
        self::adderror($path,"does not have a value in the enumeration " . implode(', ',$schema->enum));
      }
    }
    if( 
      isset($schema->maxDecimal) && 
      (  ($value * pow(10,$schema->maxDecimal)) != (int)($value * pow(10,$schema->maxDecimal))  )
    ) {
      self::adderror($path,"may only have " . $schema->maxDecimal . " digits of decimal places");
    }
  }
  
  static function adderror($path,$message) {
  	self::$errors[] = array(
      'property'=>$path,
      'message'=>$message
    );
  }
  
  /**
   * @return array
   */
  static function checkType($type, $value, $path) {
    if($type) {
      $wrongType = false;
      if(is_string($type) && $type !== 'any') {
        if($type == 'null') {
          if (!is_null($value)) {
            $wrongType = true;	
          }
        } 
        else {
          if($type == 'number') {
          	if(!in_array(gettype($value),array('integer','double'))) {
          	  $wrongType = true;	
          	}
          } elseif( $type !== gettype($value) ) {
          	$wrongType = true;
          }  
        } 
      }
      if($wrongType) {
      	return array(
      	  array(
      	    'property'=>$path,
      	    'message'=>gettype($value)." value found, but a ".$type." is required "
      	  )
      	);
      }
      // Union Types  :: for now, just return the message for the last expected type!!
      if(is_array($type)) {
        $validatedOneType = false;
        $errors = array();
        foreach($type as $tp) {
          $error = self::checkType($tp,$value,$path);
          if(!count($error)) {
          	$validatedOneType = true;
          	break;
          }
          else {
          	$errors[] = $error;
          	$errors = $error;
          }
        }
        if(!$validatedOneType) {
          return $errors; 
        }
      }
      elseif(is_object($type)) {
      	self::checkProp($value,$type,$path);
      }
    }
    return array();
  }
  
  static function checkObj($instance, $objTypeDef, $path, $additionalProp,$_changing) {
    if($objTypeDef instanceOf StdClass) {
      if( ! ($instance instanceOf StdClass) || is_array($instance) ) {
      	self::$errors[] = array(
      	  'property'=>$path,
      	  'message'=>"an object is required"
      	);
      }
      foreach($objTypeDef as $i=>$value) {
        $value = 
          array_key_exists($i,$instance) ? 
            $instance->$i : 
            new JsSchemaUndefined();
        $propDef = $objTypeDef->$i;
        self::checkProp($value,$propDef,$path,$i,$_changing);
      }
    }
    // additional properties and requires
    foreach($instance as $i=>$value) {
      // verify additional properties, when its not allowed
      if( !isset($objTypeDef->$i) && ($additionalProp === false) && $i !== '$schema' ) {
      	self::$errors[] = array(
      	  'property'=>$path,
      	  'message'=>"The property " . $i . " is not defined in the objTypeDef and the objTypeDef does not allow additional properties"
      	);
      }
      // verify requires
      if($objTypeDef && isset($objTypeDef->$i) && isset($objTypeDef->$i->requires)) {
        $requires = $objTypeDef->$i->requires;
        if(!array_key_exists($requires,$instance)) {
          self::$errors[] = array(
      	    'property'=>$path,
      	    'message'=>"the presence of the property " . $i . " requires that " . $requires . " also be present"
      	  );
        }	
      }
	  $value = $instance->$i;
	  
	  // To verify additional properties types. 
	  if ($objTypeDef && is_object($objTypeDef) && !isset($objTypeDef->$i)) {
	    self::checkProp($value,$additionalProp,$path,$i); 
      }
      // Verify inner schema definitions 			
	  $schemaPropName = '$schema';
	  if (!$_changing && $value && isset($value->$schemaPropName)) {
        self::$errors = array_merge(
          self::$errors,
          checkProp($value,$value->$schemaPropname,$path,$i)
        );
	  }
    }
	return self::$errors;
  }
}

class Dbg {
	
  static public $quietMode = false;
  
  static function func($print = true, $numStackSteps = 1) {
  	$ar = debug_backtrace();
  	$ret = '';
  	
  	for($a = $numStackSteps; $a >= 1; $a--) {
  	  $line = $ar[$a-1]['line'];
  	  $step = $ar[$a];
  	  $ret .= str_repeat('&nbsp;&nbsp;&nbsp;',$a).self::showStep($step,$print,$line);	
  	}
  	if($print && !self::$quietMode) echo $ret;
  	return $ret;
  }
  
  static function mark($title,$print = true) {
  	$ar = debug_backtrace();
  	$ret = '';
  	$line = $ar[0]['line'];
  	$ret = "[MARK]".$title.'(line '.$line.')<br/>';
  	if($print && !self::$quietMode) echo $ret;
  	return $ret;
  }
  
  static function object($object,$linkTitle,$varDump = false,$print = true) {
  	static $divCount = 0;
  	$divCount++;
  	$ar = debug_backtrace();
  	$ret = '';
  	$line = $ar[0]['line'];
  	$ret = '[OBJECT]<a href="javascript:void(0);" onclick="$(\'#div-obj-'.$divCount.'\').toggle()">'.$linkTitle.'</a>';
  	$ret .= '(line '.$line.')<br/>';
  	$ret .= '<div id=\'div-obj-'.$divCount.'\' style="display:none;">';
  	if($varDump) {
  	  ob_start();
  	  var_dump($object);
  	  $ret .= ob_get_clean();
  	}
  	else {
  	  $ret .= print_r($object,true); 
  	}
  	$ret .= '</div>';
  	if($print && !self::$quietMode) echo $ret;
  	return $ret;
  }
  
  static function showStep($step,$print,$line) {
  	static $divCount = 0;
    $ret = '[STEP]'.$step['class'] . $step['type'] . $step['function'];
  	if(count($step['args'])) {
  	  $ret .= '(';
  	  $comma = '';
  	  $exp = array();
  	  foreach($step['args'] as $num=>$arg) {
  	  	$divCount++;
  	  	if(in_array(gettype($arg),array('object','array'))) {
  	  	  if(is_object($arg)) {
  	  	    $type = get_class($arg);
  	  	  }
  	  	  else {
  	  	  	$type = gettype($arg);
  	  	  }
  	  	  $argVal = '<a href="javascript:void(0);" onclick="$(\'#div-step-'.$divCount.'\').toggle()">click to see</a>';
  	  	  $exp[] = 
  	  	    '<div id=\'div-step-'.$divCount.'\' style="display:none;">'
  	  	    .print_r($arg,true)
  	  	    .'</div>';
  	  	}
  	  	else {
  	  	  $type = gettype($arg);
  	  	  if($type == 'string') {
  	  	    $argVal = "'".$arg."'";
  	  	  }
  	  	  else {
  	  	  	$argVal = $arg;
  	  	  }
  	  	  $argVal = '<font style="color:#060">'.$argVal.'</font>';
  	  	}
  	  	$ret .= $comma.' <i>' . $type . "</i> " . $argVal;
  	  	$comma = ',';
  	  }
  	  $ret .= ')  (line '.$line.')<br/>';
  	  foreach($exp as $text) {
  	  	$ret .= '<pre>' . $text . '</pre>';
  	  }
  	}
  	return $ret;
  }
}
?>