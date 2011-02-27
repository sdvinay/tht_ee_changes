<?php

/*
=====================================================
 ExpressionEngine - by pMachine
-----------------------------------------------------
 http://www.pmachine.com/
-----------------------------------------------------
 Copyright (c) 2003 - 2004 pMachine, Inc.
=====================================================
 THIS IS COPYRIGHTED SOFTWARE
 PLEASE READ THE LICENSE AGREEMENT
 http://www.pmachine.com/expressionengine/license.html
=====================================================
 File: core.template.php
-----------------------------------------------------
 Purpose: Template parsing class.
=====================================================
*/

if ( ! defined('EXT'))
{
    exit('Invalid file request');
}


class Template {
        
    var $tag_identifier  	= 'exp';         // Tag base identifier
    var $l_delim         	= '{';           // Left tag delimiter
    var $r_delim         	= '}';           // Right tag delimiter
    var $slash           	=  '&#47;';      // Forward slash character entity
    var $loop_count      	=   0;           // Main loop counter.    
    var $depth           	=   0;           // Sub-template loop depth
    var $cur_tag_open    	=  '';           // Currently matched opening tag
    var $cur_tag_close   	=  '';           // Currently matched closing tag
    var $in_point        	=  '';           // String position of matched opening tag
    var $template        	=  '';           // The requested template (page)
    var $fl_tmpl         	=  '';           // 'Floating' copy of the template.  Used as a temporary "work area".
    var $data            	=  '';           // Used by the class/method handler to pass data to each class
    var $params          	=  '';           // ''
    var $chunk           	=  '';           // ''
    var $cache_hash      	=  '';           // md5 checksum of the template name.  Used as title of cache file.
    var $cache_status   	=  '';           // Status of page cache (NO_CACHE, CURRENT, EXPIRED)
    var $template_type  	=  '';           // Type of template (webpage, rss)
    var $template_hits   	=   0;
    var $hit_lock_override	=  FALSE;		 // Set to TRUE if you want hits tracked on sub-templates
    var $hit_lock        	=  FALSE;		 // Lets us lock the hit counter if sub-templates are contained in a template
    var $php_parse_lock  	=  FALSE;        // Lets us lock the "parse php" preference based on the primary template
    var $sub_exists 	 	=  FALSE;        // Whether a sub-template exists or not
   
    var $tag_data        	= array();       // Data contained in tags
    var	$templates_sofar	= array();       // Templates process so far
    var $modules         	= array();       // List of installed modules
    var $plugins         	= array();       // List of installed plug-ins
    var $instantiated    	= array();       // Running list of the classes that have been instatiated
    var $var_single      	= array();       // "Single" variables
    var $var_cond        	= array();       // "Conditional" variables
    var $var_pair        	= array();       // "Paired" variables
   
    var $t_cache_path    	= 'tag_cache/';  // Location of the tag cache file
    var $p_cache_path    	= 'page_cache/'; // Location of the page cache file
   
    var $marker = '0o93H7pQ09L8X1t49cHY01Z5j4TT91fG'; // Temporary marker used as a place-holder for template data
    

    // -------------------------------------
    //  Constructor
    // -------------------------------------

    function Template()
    {
		if ( ! defined('LD'))
        	define('LD', $this->l_delim);
			
		if ( ! defined('RD'))
        	define('RD', $this->r_delim);
	
		if ( ! defined('SLASH'))
        	define('SLASH',	$this->slash);
    
		if ( ! defined('TB'))
        	define('TB', $this->l_delim.$this->tag_identifier.':');        
    }
    // END
    
    
    
    // -------------------------------------
    //  Run the template engine
    // -------------------------------------

    function run_template_engine($template_group = '', $template = '')
    {
        global $OUT, $IN;
        
        // Set the name of the cache folder for both tag and page caching
        
        if ($IN->URI == '')
        {
            $this->t_cache_path .= md5('index').'/';
            $this->p_cache_path .= md5('index').'/';
        }
        else
        {
            $this->t_cache_path .= md5($IN->URI).'/';
            $this->p_cache_path .= md5($IN->URI).'/';
        }
        
                        
        $this->process_template($template_group, $template);

        $this->parse_globals();
                
        $OUT->out_type =& $this->template_type;
                
        $OUT->build_queue($this->template); 
    }
    // END
    
    
    // -------------------------------------
    //  Process Template
    // -------------------------------------

    function process_template($template_group = '', $template = '')
    {
// Following line changed by Vinay, 3/6/04, based on Rick Ellis's suggestion
//  at http://www.pmachine.com/forum/threads.php?id=13346_0_19_40_C
// 5/1/04: EE has made this change themselves, so we no longer need to make 
//  this change ourselves
        global $PREFS, $REGX, $LANG, $IN;
//        global $PREFS, $REGX, $LANG;
// End

        
        // Safety. Prevents run-away loop due to improperly nested sub-templates
        // Will not render template that starts loop.
        foreach($this->templates_sofar as $key => $value)
        {
            if ($key != $this->depth)
            {
                foreach($value as $subkey => $prev_template)
         	    {
         	        if ($prev_template == "{$template_group}/{$template}")
        	        {
        	        	$this->template = ($PREFS->ini('debug') >= 1) ? $LANG->line('template_loop') : "";
        		        return;
        	        }
                }
            }
        }
        
        // Creates array of previous processed templates by depth
        if ( ! isset( $this->templates_sofar[$this->depth]['0']))
        {
        	$this->templates_sofar[$this->depth] = array('0' => "{$template_group}/{$template}");
        }
        else
        {
        	$sub_depths = count ($this->templates_sofar[$this->depth]);
        	$this->templates_sofar[$this->depth][$sub_depths] = "{$template_group}/{$template}";
        }
        
         
        // Fetch the requested template.
        
        if ($template_group != '' AND $template != '')
        {  
            $this->template =& $this->fetch_template($template_group, $template, FALSE);
        }
        else
        {
            $this->template =& $this->parse_template_uri();
        }
        
                        
        // Set "php parse lock".  Since sub-templates can be put in templates
        // we only want the outermost template to determine if PHP is to be parsed
        
// following added by Vinay, 3/6/04, based on Rick Ellis's suggestion
// at http://www.pmachine.com/forum/threads.php?id=13346_0_19_40_C
        for ($i = 1; $i < 6; $i++)
        {
        	$this->template =& str_replace(LD.'segment_'.$i.RD, $IN->fetch_uri_segment($i), $this->template); 
        }
// end vinay's addition

        $this->php_parse_lock = TRUE;        
                             
        // If a cache file exists, no reason to go further...
        // However we do need to fetch any subtemplates
        
        if ($this->cache_status == 'CURRENT')
        {
			$this->process_sub_templates($this->template); 
			
			if ($this->sub_exists == FALSE)
            	return; 
        }
       
        // Replace forward slashes with entity to prevent preg_replace errors.
        
        $this->template =& str_replace('/', $this->slash, $this->template);

                        
        // Remove whitespace from variables
        
        $this->template =& preg_replace("/".LD."\s*(\S+)\s*".RD."/", LD."\\1".RD, $this->template);

        // Fetch installed modules and plugins
        
        if (count($this->modules) == 0)
        {
            $this->fetch_modules();
        }
        
        if (count($this->plugins) == 0)
        {
            $this->fetch_plugins();
        }
    
        // Parse the template.
        // The template is parsed in passes, the number of which depends on the
        // depth of tag nesting. Templates are parsed starting with the outermost tags. Each 
        // subsequent loop travels progressively inward.  This enables each nested tag 
        // to process the result of the enclosing tag.

        // Keep looping as long as opening tags are found
        
        while (is_int(strpos($this->template, TB)))
        {
            // Run the template parser
            
            $this->parse_template();
            
            // Run the class/method handler
            
            $this->class_handler();

            // Initialize values between loops
            
            unset($this->tag_data);
            
            $this->loop_count = 0;  
        }
        
        // Decode forward slash entities back to ascii
        
        $this->template =& str_replace($this->slash, '/', $this->template);
             
        // Write the cache file if needed
                
        if ($this->cache_status == 'EXPIRED')
        { 
            $this->write_cache_file($this->cache_hash, $this->template, 'template');
        }

		// Process embedded sub-templates
        
        $this->process_sub_templates($this->template); 
        
     }
    // END
        
        
        

    // -------------------------------------
    //  Parse the template
    // -------------------------------------

    function parse_template()
    {
        while (TRUE)  
        {
            // Make a "floating" copy of the template which we'll progressively slice into pieces with each loop
            
            $this->fl_tmpl =& $this->template;
                    
            // Identify the string position of the fist occurence of a matched tag
            
            $this->in_point = strpos($this->fl_tmpl, TB);
                              
            // If the above variable returns false we are done looking for tags
            // This single conditional keeps the template engine from spiraling 
            // out of control in an infinite loop.        
            
            if (FALSE === $this->in_point)
            {
                break;
            }
            else
            {
                //------------------------------------------
                // Process the tag data
                //------------------------------------------
                
                // These REGEXs parse out the various components contained in any given tag.
                
                // Grab the opening portion of the tag: {exp:some:tag param="value" param="value"}

                preg_match("/".TB.".*?".RD."/s", $this->fl_tmpl, $matches);
                                
                $raw_tag = preg_replace("/(\r\n)|(\r)|(\n)|(\t)/", ' ', $matches['0']);
                                
                $tag_length =& strlen($raw_tag);
                
                $data_start = $this->in_point + $tag_length;

                $tag  = trim(substr($raw_tag, 1, -1));
                $args = trim((preg_match("/\s+.*/", $tag, $matches))) ? $matches['0'] : '';
                $tag  = trim(str_replace($args, '', $tag));  
                
                $this->cur_tag_open  = LD.$tag;
                $this->cur_tag_close = LD.SLASH.$tag.RD;
                
                // -----------------------------------------
                
                // Assign the class name/method name and any parameters
                 
                $class =& $this->assign_class(substr($tag, strlen($this->tag_identifier) + 1));
                $args  =& $this->assign_parameters($args);
                
                
                // Trim the floating template, removing the tag we just parsed.
                
                $this->fl_tmpl =& substr($this->fl_tmpl, $this->in_point + $tag_length);
                
                $out_point = strpos($this->fl_tmpl, $this->cur_tag_close);
                
                // Do we have a tag pair?
                
                if (FALSE !== $out_point)
                { 
                    // Assign the data contained between the opening/closing tag pair to the master tag data array.
                
                    $block = substr($this->template, $data_start, $out_point);        
        
                    // Define the entire "chunk" - from the left edge of the opening tag 
                    // to the right edge of closing tag.

                    $out_point = $out_point + $tag_length + strlen($this->cur_tag_close);
                                        
                    $chunk = substr($this->template, $this->in_point, $out_point);
                }
                else
                {
                    // Single tag...
                    
                    $block = ''; // Single tags don't contain data blocks
                
                    // Define the entire opening tag as a "chunk"
                
                    $chunk =& substr($this->template, $this->in_point, $tag_length);                
                }
                                
                // Strip the "chunk" from the template, replacing it with a unique marker.
                
                $this->template =& preg_replace("|".preg_quote($chunk)."|s", 'M'.$this->loop_count.$this->marker, $this->template, 1);

                $cfile =& md5($chunk); // This becomes the name of the cache file

                // Build a multi-dimensional array containing all of the tag data we've assembled
                  
                $this->tag_data[$this->loop_count]['class']  = $class['0'];
                $this->tag_data[$this->loop_count]['method'] = $class['1'];
                $this->tag_data[$this->loop_count]['params'] = $args;
                $this->tag_data[$this->loop_count]['chunk']  = $chunk; // Matched data block - including opening/closing tags          
                $this->tag_data[$this->loop_count]['block']  = $block; // Matched data block - no tags
                $this->tag_data[$this->loop_count]['cache']  = $this->cache_status($cfile, $args); 
                $this->tag_data[$this->loop_count]['cfile']  = $cfile;
            
            } // END IF

          // Increment counter            
          $this->loop_count++;  

       } // END WHILE
    }
    // END


    // -------------------------------------
    //  Parse embedded sub-templates
    // -------------------------------------

    function process_sub_templates(&$template)
    {
        global $REGX;
        
        $matches = array();
    
        if ( ! preg_match_all("/".LD."embed\s*=(.*?)".RD."/s", $template, $matches))
        {
        	$this->sub_exists = FALSE;
        }
        
        $this->sub_exists = TRUE;
        
        $i = 0;
        
        if (count($matches) > 0)
        {
			foreach($matches['1'] as $val)
			{ 
				$val = $REGX->trim_slashes($REGX->strip_quotes($val));
				
				if ( ! ereg("/", $val))
				{   
					$i++;
					continue;
				}
		
				$ex = explode("/", trim($val));
				
				if (count($ex) != 2)
				{
					$i++;
					continue;
				}
				
				if ( ! isset($decrease_depth))
				{
					$this->depth++;
					$decrease_depth = "y";
				}
					
				$this->process_template($ex['0'], $ex['1']);
		
				$this->template =& str_replace($matches['0'][$i++], $this->template, $template);
				
				$template = &$this->template;            
			}
		}
		        
        if (isset($decrease_depth))
        {
        	$this->templates_sofar[$this->depth] = array();
        	$this->depth--;
        }
    }
    // END



    // -------------------------------------
    //  Class/Method handler
    // -------------------------------------

    function class_handler()
    {    
        $classes = array();
        
        // Fill an array with the names of all the classes that we previously extracted from the tags
                
        for ($i = 0; $i < count($this->tag_data); $i++)
        {
            // Should we use the tag cache file?

            if ($this->tag_data[$i]['cache'] == 'CURRENT')
            {            
                // If so, replace the marker in the tag with the cache data    
                
                $this->replace_marker($i, $this->get_cache_file($this->tag_data[$i]['cfile']));
            }
            else
            {
                // Is a module or plug-in being requested?                
            
                if ( ! in_array($this->tag_data[$i]['class'] , $this->modules))
                {                 
                    if ( ! in_array($this->tag_data[$i]['class'] , $this->plugins))
                    {                    
                        global $LANG, $PREFS, $OUT;

                        if ($PREFS->ini('debug') >= 1)
                        {
                            $error  = $LANG->line('error_tag_syntax');
                            $error .= '<br /><br />';
                            $error .= htmlspecialchars(LD.$this->tag_identifier.':');
                            $error .= strtolower($this->tag_data[$i]['class']);                       
                            $error .= (strtolower($this->tag_data[$i]['class']) != $this->tag_data[$i]['method']) ? ':'.$this->tag_data[$i]['method'] : '';                 
                            $error .= htmlspecialchars(RD);
                            $error .= '<br /><br />';
                            $error .= $LANG->line('error_fix_syntax');
            
                            $OUT->fatal_error($error);                         
                        }
                        else
                            return false;             
                    }
                    else
                        $classes[] = 'pi.'.$this->tag_data[$i]['class'];
                }
                else
                    $classes[] = $this->tag_data[$i]['class'];
            }
        }

        // Remove duplicate class names and re-order the array
        
        $classes = array_values(array_unique($classes));
        
        // Dynamically require the file that contains each class
        
        for ($i = 0; $i < count($classes); $i++)
        {
            // But before we do, make sure it hasn't already been included...
            
            if ( ! in_array($classes[$i], $this->instantiated))
            {
                if (substr($classes[$i], 0, 3) == 'pi.')
                {
                    require_once PATH_PI.$classes[$i].EXT;                 
                }
                else
                {
                    require_once PATH_MOD.$classes[$i].'/mod.'.$classes[$i].EXT;
                }
                
                $this->instantiated[] = $classes[$i]; 
            }
        }
                
        // Final data processing

        // Loop through the master array containing our extracted template data
        
        reset($this->tag_data);
        
        for ($i = 0; $i < count($this->tag_data); $i++)
        { 
            if ($this->tag_data[$i]['cache'] != 'CURRENT')
            {
                // Assign the data chunk, parameters
                
                $this->tagdata   =& $this->tag_data[$i]['block'];
                $this->tagparams =& $this->tag_data[$i]['params']; 
                $this->tagchunk  =& $this->tag_data[$i]['chunk'];
                
                // Fetch the variables for this particular tag
                               
                $this->assign_variables($this->tag_data[$i]['block']);
                   
                // Assign the class name and method name
            
                $class_name =& ucfirst($this->tag_data[$i]['class']);
                $meth_name  =& $this->tag_data[$i]['method'];
                
                // Dynamically instantiate the class.
                        
                $EE = new $class_name();
                
                // Does the method exist?  If not, balk.
        
                if ( ! method_exists($EE, $meth_name))
                {
                    global $LANG, $PREFS, $OUT;

                    if ($PREFS->ini('debug') >= 1)
                    {                        
                        $error  = $LANG->line('error_tag_syntax');
                        $error .= '<br /><br />';
                        $error .= htmlspecialchars(LD.$this->tag_identifier.':');
                        $error .= strtolower($this->tag_data[$i]['class']);                       
                        $error .= (strtolower($this->tag_data[$i]['class']) != $this->tag_data[$i]['method']) ? ':'.$this->tag_data[$i]['method'] : '';                 
                        $error .= htmlspecialchars(RD);
                        $error .= '<br /><br />';
                        $error .= $LANG->line('error_fix_syntax');
                        
                        $OUT->fatal_error($error);
                     }
                     else
                        return;
                }    
                
                /*
                
                OK, lets grab the data returned from the class.
                
                First, however, lets determine if the tag has one or two segments.  
                If it only has one, we don't want to call the constructor again since
                it was already called during instantiation.
         
                Note: If it only has one segment, only the object constructor will be called.Ê 
                Since constructors can't return a value just by initialializing the object
                the output of the class must be assigned variable called $this->return_data
                               
                */
                
                if (strtolower($class_name) == $meth_name)
                {
                    $return_data = (isset($EE->return_data)) ? $EE->return_data : '';
                }
                else
                {
                    $return_data = $EE->$meth_name();
                }
              
               // Write cache file if needed
               
                if ($this->tag_data[$i]['cache'] == 'EXPIRED')
                {
                    $this->write_cache_file($this->tag_data[$i]['cfile'], $return_data);
                }
                
                // Replace the temporary markers we added earlier with the fully parsed data
              
                $this->replace_marker($i, $return_data);
                
                // Initialize data in case there are susequent loops                
                
                $this->var_single = array();
                $this->var_cond   = array();
                $this->var_pair   = array();
                                
                unset($return_data);
                unset($class_name);    
                unset($meth_name);    
                unset($EE);
            }
        }
    }
    // END



    // -------------------------------------
    //  Assign class and method name
    // -------------------------------------

    function &assign_class(&$tag)
    {
        $result = array();
    
        // Grab the class name and method names contained 
        // in the tag and assign them to variables.
        
        $z = explode(':', $tag);
        
        // Tags can either have one segment or two:
        // {exp:first_segment}
        // {exp:first_segment:second_segment}
        //
        // These two segments represent either a "class:constructor"
        // or a "class:method".  We need to determine which one it is.
        
        if (count($z) == 1)
        {                
            $result['0'] = trim($z['0']);
            $result['1'] = trim($z['0']);
        }
        elseif (count($z) == 2)
        {                
            $result['0'] = trim($z['0']);
            $result['1'] = trim($z['1']);
        }
        
        return $result;
    }
    // END



    // -------------------------------------
    //  Return parameters as an array
    // -------------------------------------
    
    //  Creates an associative array from a string
    //  of parameters: sort="asc" limit="2" etc.
    
    function &assign_parameters(&$str)
    {
        if ($str == "")
            return false;
                        
        if ($str == "")
            return false;

		// \047 - Single quote octal
		// \042 - Double quote octal
		
		// I don't know for sure, but I suspect using octals is more reliable than ASCII.
		// I ran into a situation where a quote wasn't being matched until I switched to octal.
		// I have no idea why, so just to be safe I used them here. - Rick
		
		preg_match_all("/(\S+?)\s*=[\042\047](\s*.+?\s*)[\042\047]\s*/", $str, $matches);

		if (count($matches) > 0)
		{		
			$result = array();
		
			for ($i = 0; $i < count($matches['1']); $i++)
			{
				$result[$matches['1'][$i]] = $matches['2'][$i];
			}
			
			return $result;
		}
  
        return false;
    }
    // END



    // ---------------------------------------
    //  Fetch a specific parameter
    // ---------------------------------------
        
    function fetch_param($which)
    {
        return ( ! isset($this->tagparams[$which])) ? FALSE : $this->tagparams[$which];
    }
    // END



    // ---------------------------------------
    //  Assign Tag Variables
    // ---------------------------------------
    /*
        This function extracts the variables contained within the current tag 
        being parsed and assigns them to one of three arrays.
        
        There are three types of variables:
        
        Simple variables: {some_variable}
    
        Paired variables: {variable} stuff... {/variable}

        Contidionals: {if something != 'val'} stuff... {if something}
        
        Each of the three variables is parsed slightly different.
        
    */
            
    function assign_variables($str = '')
    {
        if ($str == '')
            return false;
    
        if ( ! preg_match_all("/".LD."(.*?)".RD."/s", $str, $matches))
        {
            return false;
        }
        
        // First We'll separate the three variable types into three separate arrays
        
        $temp_cond  = array();
        $temp_close = array();
        $temp_misc  = array();
                
        foreach($matches['1'] as $val)
        { 
            if (eregi("^if ", $val))
            {
                $temp_cond[] = $val;
            }
            else
            {
                if (ereg("^".SLASH."if ", $val))
                {
                    continue;
                }
                elseif (ereg("^".SLASH, $val))
                {
                    $temp_close[] = str_replace(SLASH, '', $val);
                }
                else
                {
                    $temp_misc[] = $val;
                }
            }
        }
        
        $temp_pair = array();
        
        foreach($temp_misc as $item)
        {
            foreach($temp_close as $row)
            {
                if (ereg("^".$row, $item))
                {
                    $temp_pair[] = $item;
                }
            }
        }
        
        $temp_single = array_unique(array_diff($temp_misc, $temp_pair));
        $temp_cond   = array_unique($temp_cond);
        $temp_pair   = array_unique($temp_pair);
        
        // Single variables
                
        foreach($temp_single as $val)
        {  
            // simple conditionals
        
            if (ereg('\|', $val) AND ! eregi("^switch", $val))
            {
                $this->var_single[$val] = $this->fetch_simple_conditions($val);
            }
            
            // date variables
            
            elseif (preg_match("/format/", $val))
            {
                $this->var_single[$val] = $this->fetch_date_variables($val);  
            }
            
            // single variables
            
            else
            {
                $this->var_single[$val] = $val;  
            }
        }

        // Variable pairs
            
        foreach($temp_pair as $val)
        {
            $this->var_pair[$val] = $this->assign_parameters($val);       
        }
        
        
        // Conditional variables 
       
        foreach($temp_cond as $val)
        {
            $val = str_replace("|", "\|", $val);
        
            if (preg_match_all("/(\S+?)\s*(\!=|==|<|>|<=|>=|<>)/s", $val, $matches))
            {
                $this->var_cond[$val] = $matches['1'];  
            }
            else
            {            
                $this->var_cond[$val] = array(trim(preg_replace("/^if/", "", $val))); 
            }
        }  
    }
    // END



    // ---------------------------------------
    //  Swap single variables with final value
    // ---------------------------------------

    function swap_var_single($search, $replace, $source)
    {
        return str_replace(LD.$search.RD, $replace, $source);  
    }
    // END



    // ---------------------------------------
    //  Swap variable pairs with final value
    // ---------------------------------------

    function swap_var_pairs($open, $close, &$source)
    {
        return preg_replace("/".LD.$open.RD."(.*?)".LD.SLASH.$close.RD."/s", "\\1", $source); 
    }
    // END


    // ---------------------------------------
    //  Delete variable pairs
    // ---------------------------------------

    function delete_var_pairs(&$open, $close, &$source)
    {
        return preg_replace("/".LD.$open.RD."(.*?)".LD.SLASH.$close.RD."/s", "", $source); 
    }
    // END


    // ---------------------------------------
    //  Fetch date variables
    // ---------------------------------------
    //
    // This function looks for a variable that has this prototype:
    //
    // {date format="%Y %m %d"}
    //
    // If found, returns only the datecodes: %Y %m %d

    function fetch_date_variables(&$datestr)
    {
        if ($datestr == '')
            return;
        
        if ( ! preg_match("/format\s*=\s*[\'|\"](.*?)[\'|\"]/s", $datestr, $match))
               return false;
        
        return $match['1'];
    }
    // END



    // ---------------------------------------
    //  Fetch simple conditionals
    // ---------------------------------------

    function fetch_simple_conditions(&$str)
    {
        if ($str == '')
            return;
            
        if (ereg('^\|', $str))
            $str = substr($str, 1);
            
        if (ereg('\|$', $str))
            $str = substr($str, 0, -1);
            
        $str = str_replace(' ', '', trim($str));        
        
        return explode('|', $str);
    }
    // END


    // -----------------------------------------
    //  Fetch the data in between two variables
    // -----------------------------------------

    function fetch_data_between_var_pairs(&$str, $variable)
    {
        if ($str == '' || $variable == '')
            return;
        
        if ( ! preg_match("/".LD.$variable.".*?".RD."(.*?)".LD.SLASH.$variable.RD."/s", $str, $match))
               return;
 
        return $match['1'];        
    }
    // END


    // ---------------------------------------
    //  Replace marker with final data
    // ---------------------------------------

    function replace_marker(&$i, &$return_data)
    {       
        $this->template =& str_replace('M'.$i.$this->marker, $return_data, $this->template);
    }
    // END


    // -----------------------------------------
    //  Set caching status
    // -----------------------------------------

    function cache_status($cfile, $args, $cache_type = 'tag')
    {
        // Three caching states:  

        // NO_CACHE = do not cache 
        // EXPIRED  = cache file has expired
        // CURRENT  = cache file has not expired
                        
        if ( ! isset($args['cache']))
            return 'NO_CACHE';
            
        if ($args['cache'] != 'yes')
            return 'NO_CACHE';

        $cache_dir = ($cache_type == 'tag') ? PATH_CACHE.$this->t_cache_path : $cache_dir = PATH_CACHE.$this->p_cache_path;

        $cache_file = $cache_dir.'t_'.$cfile;
        
        if ( ! file_exists($cache_file))
            return 'EXPIRED';
        
        if ( ! $fp = @fopen($cache_file, 'rb'))
            return 'EXPIRED';
            
            flock($fp, LOCK_SH);
            
            $timestamp = trim(@fread($fp, filesize($cache_file)));
            
            flock($fp, LOCK_UN);
            
            fclose($fp);
            
            $refresh = ( ! isset($args['refresh'])) ? 0 : $args['refresh']; 
                    
        if (time() > ($timestamp + ($refresh * 60)))
        {
            return 'EXPIRED';   
        }
        else
        {
            if ( ! file_exists($cache_dir.'c_'.$cfile))
            {
                return 'EXPIRED';
            }
            
            return 'CURRENT';
        } 
    }
    // END



    // -----------------------------------------
    //  Get cache file
    // -----------------------------------------

    function get_cache_file($cfile, $cache_type = 'tag')
    {
        $cache = '';

        $cache_dir = ($cache_type == 'tag') ? PATH_CACHE.$this->t_cache_path : $cache_dir = PATH_CACHE.$this->p_cache_path;

        $fp = @fopen($cache_dir.'c_'.$cfile, 'rb');
        
        flock($fp, LOCK_SH);
                    
        $cache = @fread($fp, filesize($cache_dir.'c_'.$cfile));
                    
        flock($fp, LOCK_UN);
        
        fclose($fp);
        
        return $cache;
    }
    // END



    // -----------------------------------------
    //  Write cache file
    // -----------------------------------------

    function write_cache_file(&$cfile, &$data, $cache_type = 'tag')
    {
        $cache_dir = ($cache_type == 'tag') ? PATH_CACHE.$this->t_cache_path : $cache_dir = PATH_CACHE.$this->p_cache_path;
        
        $time_file  = $cache_dir.'t_'.$cfile;
        $cache_file = $cache_dir.'c_'.$cfile;
        
        if ( ! is_dir($cache_dir))
        {
            if ( ! @mkdir($cache_dir, 0775))
            {
                return;
            }
            
            @chmod($cache_dir, 0775);            
        }
        
        // Write the timestamp file

        if ( ! $fp = @fopen($time_file, 'wb'))
            return;

        flock($fp, LOCK_EX);
        
        fwrite($fp, time());
                
        flock($fp, LOCK_UN);
        
        fclose($fp);

        // Write the data cache

        if ( ! $fp = @fopen($cache_file, 'wb'))
            return;

        flock($fp, LOCK_EX);
        
        fwrite($fp, $data);
        
        flock($fp, LOCK_UN);
        
        fclose($fp);
    }
    // END



    // -------------------------------------
    //  Parset Template URI Data
    // -------------------------------------

    function parse_template_uri()
    {
        global $PREFS, $LANG, $OUT, $DB, $IN, $REGX;
        
        $template_group = '';
        $template = 'index';
        
        $show_default = TRUE;
        
        // -------------------------------------
        //  Do we have URI data to parse?
        // -------------------------------------
        
        if ($IN->fetch_uri_segment(1))
        {
            $show_default = FALSE;
        
            // ---------------------------------------------------------------
            //  Build an array map of template group names and templates names
            // ---------------------------------------------------------------
            
            // We use the map in order to determine what we should show
        
            $sql = "SELECT exp_template_groups.group_name, 
                           exp_template_groups.is_site_default, 
                           exp_templates.template_name
                    FROM   exp_template_groups, exp_templates
                    WHERE  exp_template_groups.group_id = exp_templates.group_id ";
                    
                
            // -----------------------------------------
            //  Adjust the query if we have a user blog
            // -----------------------------------------
            
            if (USER_BLOG === FALSE)
            {
                $sql .= " AND exp_template_groups.is_user_blog = 'n' ";
            }
            else
            {
                $sql .= " AND exp_template_groups.group_id = '".UB_TMP_GRP."' ";
            }     
                    
            $sql .= "ORDER BY exp_template_groups.group_name, exp_templates.template_name";
            

            // -----------------------------------------
            //  Run query
            // -----------------------------------------
    
            $query = $DB->query($sql);
            
            if ($query->num_rows == 0)
            {
                return false;
            }
            
            // -----------------------------------------
            //  Fill an array with the group names
            // -----------------------------------------
                                            
            $groups = array();
            
            $temp = '';
            
            foreach($query->result as $row)
            {
                if ($temp != $row['group_name'])
                {            
                    $groups[$row['group_name']] = '';
                }
                
                if (USER_BLOG === FALSE)
                {
                    if ($row['is_site_default'] == 'y')
                    {
                        $template_group = $row['group_name'];
                    }
                }
                else
                {
                    $template_group = $row['group_name'];
                }
                            
                $temp = $row['group_name'];            
            }
            
            // -----------------------------------------
            //  Fill an array with template names
            // -----------------------------------------
            
            $templates = array();
            
            foreach ($groups as $key => $val)
            {
                $temp = array();
            
                foreach ($query->result as $row)
                {    
                    if ($key == $row['group_name'])
                    {
                        $temp[$row['template_name']] = $row['template_name'];
                    }              
                }
                        
                $templates[$key] = $temp;
            }
                    
            // -----------------------------------------
            //  Determine what template we should show
            // -----------------------------------------
            
            // Compare the arrays against the URI string in order to determine 
            // what template we should display. And while were at it, determine which 
            // segment(s) contain any query data and assign those to the $IN-QSTR variable 
            // so that the various tag parsing classes can access it.
            // We face a challenge becuase ExpressionEngine does not use query strings.
            // URLs are segment driven, making it trickier to parse URIs
        
            if ( ! isset( $templates[$IN->fetch_uri_segment(1)] ))
            {
                if (isset( $templates[$template_group][$IN->fetch_uri_segment(1)] ))
                {
                    $template = $templates[$template_group][$IN->fetch_uri_segment(1)];
                    
                    if ($IN->fetch_uri_segment(2))
                    {
                        $IN->QSTR = preg_replace("#".'/'.$IN->fetch_uri_segment(1)."#", '', $IN->URI);
                    }
                }
                else
                {
                   $IN->QSTR = $IN->URI;
                }
            }
            else
            {
                $template_group = $IN->fetch_uri_segment(1);
                
                if ( ! $IN->fetch_uri_segment(2))
                {
                    $template = 'index';
                }
                else
                {
                    if ( ! isset( $templates[$template_group][$IN->fetch_uri_segment(2)] ))
                    {
                        $template = 'index';

                        if ( ! $IN->fetch_uri_segment(3) )
                        {
                            $IN->QSTR = $IN->fetch_uri_segment(2);
                        }
                        else
                        {
                           $IN->QSTR = '/'.preg_replace("#".'/'.$IN->fetch_uri_segment(1)."/#", '', $IN->URI);
                        }
                    }
                    else
                    {
                        $template = $IN->fetch_uri_segment(2);
                        
                        if ( ! $IN->fetch_uri_segment(3) AND $IN->fetch_uri_segment(2) != 'index')
                        {
                            $IN->QSTR = $IN->fetch_uri_segment(2);
                        }
                        else
                        {
                            $IN->QSTR = preg_replace("#".'/'.$IN->fetch_uri_segment(1).'/'.$IN->fetch_uri_segment(2)."#", '', $IN->URI);
                        }
                    }
                }
            }
        
            $IN->QSTR = $REGX->trim_slashes($IN->QSTR);        
        }
        
       return $this->fetch_template($template_group, $template, $show_default);
    }
    // END



    // -----------------------------------------
    //  Fetch the requested template
    // -----------------------------------------

    function fetch_template($template_group, $template, $show_default = TRUE)
    {
        global $PREFS, $LANG, $OUT, $DB, $IN, $SESS;

        $sql = "SELECT exp_templates.template_name, 
                       exp_templates.template_id, 
                       exp_templates.template_data, 
                       exp_templates.template_type, 
                       exp_templates.cache, 
                       exp_templates.refresh, 
                       exp_templates.no_auth_bounce, 
                       exp_templates.allow_php, 
                       exp_templates.hits
                FROM   exp_template_groups, exp_templates
                WHERE  exp_template_groups.group_id = exp_templates.group_id
                AND    exp_templates.template_name = '".$template."' ";
                
        if ($show_default == TRUE && USER_BLOG === FALSE)
        {
            $sql .= "AND exp_template_groups.is_site_default = 'y'";
        }
        else
        {
            $sql .= "AND exp_template_groups.group_name = '".$template_group."'";
        }
                
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
            return false;
        }
        
        // -----------------------------------------
        //  Is PHP allowed in this template?
        // -----------------------------------------
        
        // If so, we'll set a flag so the output class knows to parse it
        
        
        if ($query->row['allow_php'] == 'y' AND $this->php_parse_lock == FALSE)
        {
            $OUT->parse_php = TRUE;
        }
        
        // ----------------------------------------------------
        //  Is the current user allowed to view this template?
        // ----------------------------------------------------
        
        if ($query->row['no_auth_bounce'] != '')
        { 
            $result = $DB->query("SELECT count(*) AS count FROM exp_template_no_access WHERE template_id = '".$query->row['template_id']."' AND member_group = '".$SESS->userdata['group_id'] ."'");
            
            if ($result->row['count'] > 0)
            { 
                $sql = "SELECT template_id, template_data, template_name, template_type, cache, refresh, hits
                        FROM   exp_templates
                        WHERE  template_id = '".$query->row['no_auth_bounce']."'";
        
                $query = $DB->query($sql);
            }
        }
        
        if ($query->num_rows == 0)
        {
            return false;
        }
        
        // -----------------------------------------
        //  Increment hit counter
        // -----------------------------------------
        
        if ($this->hit_lock == FALSE OR $this->hit_lock_override == TRUE)
        {
            $this->template_hits = $query->row['hits'] + 1;
            $this->hit_lock = TRUE;
            
            $DB->query("UPDATE exp_templates SET hits = '".$this->template_hits."' WHERE template_id = '".$query->row['template_id']."'");
        }
                

        // -----------------------------------------
        //  Set template type for our page headers
        // -----------------------------------------

        if ($this->template_type == '')
        {
            $this->template_type = $query->row['template_type'];
        }
        // -----------------------------------------
        //  Retreive cache
        // -----------------------------------------
              
		$this->cache_hash = md5($template);

        if ($query->row['cache'] == 'y')
        {
            $this->cache_status = $this->cache_status($this->cache_hash, array('cache' => 'yes', 'refresh' => $query->row['refresh']), 'template');
         
            if ($this->cache_status == 'CURRENT')
            {
                return $this->get_cache_file($this->cache_hash, 'template');                
            }            
        }

        return $query->row['template_data'];
    }
    // END




    // -------------------------------------
    //   Fetch installed modules
    // -------------------------------------
    
    function fetch_modules()
    {
        global $PREFS;
    
        $filelist = array();
    
        if ($fp = @opendir(PATH_MOD)) 
        { 
            while (false !== ($file = readdir($fp))) 
            { 
                $filelist[count($filelist)] = $file;
            } 
        } 
    
        closedir($fp); 
        
        sort($filelist);
    
        for ($i =0; $i < sizeof($filelist); $i++) 
        {
            if ( ! ereg("\.",  $filelist[$i]))
            {                
                array_push($this->modules, $filelist[$i]);
            }
        } 
    }
    // END



    // -------------------------------------
    //  Fetch installed plugins
    // -------------------------------------
    
    function fetch_plugins()
    {
        global $PREFS;
    
        $filelist = array();
    
        if ($fp = @opendir(PATH_PI)) 
        { 
            while (false !== ($file = readdir($fp))) 
            { 
                $filelist[count($filelist)] = $file;
            } 
        } 
    
        closedir($fp); 
        
        sort($filelist);
            
        for ($i =0; $i < sizeof($filelist); $i++) 
        {
            if ( eregi(EXT."$",  $filelist[$i]))
            {
                if (substr($filelist[$i], 0, 3) == 'pi.')
                    array_push( $this->plugins,
                                substr($filelist[$i], 3, - strlen(EXT))
                              );
            }
        }        
    }
    // END



    // -------------------------------------
    //  Parse system global variables
    // -------------------------------------

    // The sintax is generally: {global:variable_name}
    
    function parse_globals()
    {    
        global $LANG, $PREFS, $FNS, $DB;
        
        $charset 	= '';
        $lang		= '';
        
        // --------------------------------------------------
        //  {homepage}
        // --------------------------------------------------
        
        $this->template =& str_replace(LD.'homepage'.RD, $FNS->fetch_site_index(), $this->template); 
                
        // --------------------------------------------------
        //  {site_name}
        // --------------------------------------------------

        $this->template =& str_replace(LD.'site_name'.RD, $PREFS->ini('site_name'), $this->template);

        // --------------------------------------------------
        //  Stylesheet variable: {stylesheet=group/template}
        // --------------------------------------------------

        $qs = ($PREFS->ini('force_query_string') == 'y') ? '' : '?';

        $this->template =& preg_replace("/".LD."\s*stylesheet=(.*?)".RD."/", $FNS->fetch_site_index().$qs."css=\\1", $this->template);
          
          
        // --------------------------------------------------
        //  Email encode: {encode="you@yoursite.com" title="click Me"}
        // --------------------------------------------------

		if (preg_match("/".LD."\s*encode=(.+?)".RD."/i", $this->template))
        {
			$this->template =& preg_replace_callback("/".LD."\s*encode=(.+?)".RD."/", array($FNS, 'encode_email'), $this->template);
		}

        // --------------------------------------------------
        //  Path variable: {path=group/template}
        // --------------------------------------------------

        $this->template =& preg_replace_callback("/".LD."\s*path=(.*?)".RD."/", array($FNS, 'create_url'), $this->template);
        
        // --------------------------------------------------
        //  Debug mode: {debug_mode}
        // --------------------------------------------------
        
        $this->template =& str_replace(LD.'debug_mode'.RD, ($PREFS->ini('debug') > 0) ? $LANG->line('on') : $LANG->line('off'), $this->template);
                
        // --------------------------------------------------
        //  GZip mode: {gzip_mode}
        // --------------------------------------------------

        $this->template =& str_replace(LD.'gzip_mode'.RD, ($PREFS->ini('gzip_output') == 'y') ? $LANG->line('enabled') : $LANG->line('disabled'), $this->template);
                
        // --------------------------------------------------
        //  App version: {version}
        // --------------------------------------------------
        
        $this->template =& str_replace(LD.'app_version'.RD, APP_VER, $this->template); 
       
        // --------------------------------------------------
        //  Character encoding {charset}
        // --------------------------------------------------
        
		if (preg_match("/\{charset\}/i", $this->template))
        {
        	if ( ! USER_BLOG)
        	{
				$this->template =& str_replace(LD.'charset'.RD, $PREFS->ini('charset'), $this->template); 
        	}
        	else
        	{
        		if ($charset == '')
        		{
        			$query = $DB->query("SELECT blog_lang, blog_encoding FROM exp_weblogs WHERE weblog_id = '".UB_BLOG_ID."'");

					$charset = $query->row['blog_encoding'];
					$lang	 = $query->row['blog_lang'];
					
					$this->template =& str_replace(LD.'charset'.RD, $charset, $this->template); 
        		}
        	}
        }
        
        
        // --------------------------------------------------
        //  Language {lang}
        // --------------------------------------------------

		if (preg_match("/\{lang\}/i", $this->template))
        {
        	if ( ! USER_BLOG)
        	{
				$this->template =& str_replace(LD.'lang'.RD, $PREFS->ini('xml_lang'), $this->template); 
        	}
        	else
        	{
        		if ($lang == '')
        		{
        			$query = $DB->query("SELECT blog_lang, blog_encoding FROM exp_weblogs WHERE weblog_id = '".UB_BLOG_ID."'");

					$charset = $query->row['blog_encoding'];
					$lang	 = $query->row['blog_lang'];
					
					$this->template =& str_replace(LD.'lang'.RD, $lang, $this->template); 
        		}
        	}
        }
        
        
        // --------------------------------------------------
        //  Parse User-defined Global Variables
        // --------------------------------------------------
     	
		$ub_id = ( ! defined('UB_BLOG_ID')) ? 0 : UB_BLOG_ID; 
     
		$query = $DB->query("SELECT variable_name, variable_data FROM exp_global_variables WHERE user_blog_id = '$ub_id' ");
		
		if ($query->num_rows > 0)
		{
			foreach ($query->result as $row)
			{
				$this->template =& str_replace(LD.$row['variable_name'].RD, $row['variable_data'], $this->template); 
			}
		}
		        
    }
    // END
}
// END CLASS
?>