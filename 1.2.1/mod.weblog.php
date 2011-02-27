<?php

/*
=====================================================
 ExpressionEngine - by pMachine
-----------------------------------------------------
 http://www.pmachine.com/
-----------------------------------------------------
 Copyright (c) 2003,2004,2005 pMachine, Inc.
=====================================================
 THIS IS COPYRIGHTED SOFTWARE
 PLEASE READ THE LICENSE AGREEMENT
 http://www.pmachine.com/expressionengine/license.html
=====================================================
 File: mod.weblog.php
-----------------------------------------------------
 Purpose: Weblog class
=====================================================
*/

if ( ! defined('EXT'))
{
    exit('Invalid file request');
}


class Weblog {

    var $limit	= '500';   // Default maximum query results.

  	// These variable are all set dynamically
  	
    var $query;
    var $TYPE;  
    var	$uri					= '';
    var $uristr					= '';
    var $return_data    		= '';     	// Final data 
    var $tb_action_id   		= '';
	var $basepath				= '';
    var	$sql					= FALSE;
    var $display_tb_rdf			= FALSE;
    var $cfields        		= array();
    var $mfields        		= array();
    var $categories     		= array();
    var $weblog_name     		= array();
    var $reserved_cat_segment 	= '';
	var $use_category_names		= FALSE;
	var $dynamic_sql			= FALSE;
	var $tb_captcha_hash		= '';
    
    // These are used with the nested category trees
    
    var $category_list  		= array();
	var $cat_full_array			= array();
	var $cat_array				= array();
	var $temp_array				= array();    

	// Pagination variables
	
    var $paginate				= FALSE;
	var $field_pagination		= FALSE;
    var $paginate_data			= '';
    var $pagination_links		= '';
    var $page_next				= '';
    var $page_previous			= '';
	var $current_page			= 1;
	var $total_pages				= 1;
	var $multi_fields			= array();
	var $display_by				= '';
	var $total_rows				=  0;
	var $pager_sql				= '';
	var $p_limit					= '';
	var $p_page					= '';

	
	// SQL Caching
	
	var $sql_cache_dir			= 'sql_cache/';

    // ----------------------------------------
    //  Constructor
    // ----------------------------------------

    function Weblog()
    { 
		global $PREFS;
		
		$this->p_limit = $this->limit;
		
		if ($PREFS->ini("use_category_name") == 'y' AND $PREFS->ini("reserved_category_word") != '')
		{
		$this->use_category_names		= $PREFS->ini("use_category_name");
		$this->reserved_cat_segment 		= $PREFS->ini("reserved_category_word");
		}
    }
    // END


    // ----------------------------------------
    //  Initialize values
    // ----------------------------------------

    function initialize()
    {
        $this->sql 			= '';
        $this->return_data	= '';
    }
    // END
    
    
    // ----------------------------------------
    //  Fetch Cache
    // ----------------------------------------

    function fetch_cache($identifier = '')
    {
    	global $IN, $TMPL;
    		    		
		$tag = ($identifier == '') ? $TMPL->tagproper : $TMPL->tagproper.$identifier;
    	
		$cache_file = PATH_CACHE.$this->sql_cache_dir.md5($tag.$this->uri);
    
		if ( ! $fp = @fopen($cache_file, 'rb'))
		{
			return FALSE;
		}
		
		flock($fp, LOCK_SH);
		$sql = @fread($fp, filesize($cache_file));
		flock($fp, LOCK_UN);
		fclose($fp);	
		
		return $sql;
    }
	// END    
	
    // ----------------------------------------
    //  Save Cache
    // ----------------------------------------

	function save_cache($sql, $identifier = '')
	{
		global $IN, $TMPL;
	
		$tag = ($identifier == '') ? $TMPL->tagproper : $TMPL->tagproper.$identifier;
	
		$cache_dir  = PATH_CACHE.$this->sql_cache_dir;
		$cache_file = $cache_dir.md5($tag.$this->uri);
			
		if ( ! is_dir($cache_dir))
		{
			if ( ! @mkdir($cache_dir, 0777))
			{
				return FALSE;
			}
			@chmod($cache_dir, 0777);            
		}	
		
		if ( ! $fp = @fopen($cache_file, 'wb'))
		{
			return FALSE;
		}
		
		flock($fp, LOCK_EX);
		fwrite($fp, $sql);
		flock($fp, LOCK_UN);
		fclose($fp);
		@chmod($cache_file, 0777);
		
		return TRUE;
	}
	// END
	

    // ----------------------------------------
    //  Weblog entries
    // ----------------------------------------

    function entries()
    {
        global $IN, $PREFS, $DB, $TMPL, $FNS;
                 
        $this->initialize();
        
		$this->uri = ($IN->QSTR != '') ? $IN->QSTR : 'index.php';

        $enable = array(
        					'categories' 	=> TRUE, 
        					'pagination' 	=> TRUE, 
        					'member_data'	=> TRUE, 
        					'custom_fields'	=> TRUE, 
        					'trackbacks'		=> TRUE
        					);
        
		if ($disable = $TMPL->fetch_param('disable'))
		{
			if (ereg("\|", $disable))
			{				
				foreach (explode("|", $disable) as $val)
				{
					if (isset($enable[$val]))
					{  
						$enable[$val] = FALSE;
					}
				}
			}
		}
         
        if ($enable['custom_fields'] == TRUE)
        {
        		$this->fetch_custom_weblog_fields();
        }
        
        if ($enable['member_data'] == TRUE)
        {
        		$this->fetch_custom_member_fields();
        	}
        	
        if ($enable['pagination'] == TRUE)
        {
			$this->fetch_pagination_data();
		}
        
        $save_cache = FALSE;
        
        if ($PREFS->ini('enable_sql_caching') == 'y')
        {        
			if (FALSE == ($this->sql = $this->fetch_cache()))
			{        			
				$save_cache = TRUE;
			}
			
			if (FALSE !== ($cache = $this->fetch_cache('pagination_count')))
			{
				if (FALSE !== ($this->fetch_cache('field_pagination')))
				{
					if (FALSE !== ($pg_query = $this->fetch_cache('pagination_query')))
					{					$this->paginate = TRUE;
										$this->field_pagination = TRUE;
						$this->create_pagination(trim($cache), $DB->query(trim($pg_query)));  
					}
				}
				else
				{
					$this->create_pagination(trim($cache));        		
				}
			}
        }
        
        if ($this->sql == '')
        {
	        $this->build_sql_query();
    	}

        if ($this->sql == '')
        {
        	return $TMPL->no_results();
        }

        if ($save_cache == TRUE)
        {
			$this->save_cache($this->sql);
		}
                
        $this->query = $DB->query($this->sql);
                        
        if ($this->query->num_rows == 0)
        {
        	return $TMPL->no_results();
        }
                
        if ( ! class_exists('Typography'))
        {
            require PATH_CORE.'core.typography'.EXT;
        }
                
        $this->TYPE = new Typography;   
          
        if ($enable['categories'] == TRUE)
        {
       		$this->fetch_categories();
       	}
        
        if ($enable['trackbacks'] == TRUE)
        {
        		$this->tb_action_id = $FNS->fetch_action_id('Trackback_CP', 'receive_trackback');
		}
        $this->parse_weblog_entries();
            
        if ($enable['pagination'] == TRUE)
        {
			$this->add_pagination_data();
		}
                                
        return $this->return_data;        
    }
    // END


    // ----------------------------------------
    //  Fetch pagination data
    // ----------------------------------------

    function fetch_pagination_data()
    {
		global $TMPL, $FNS;
				
		if (preg_match("/".LD."paginate".RD."(.+?)".LD.SLASH."paginate".RD."/s", $TMPL->tagdata, $match))
		{
			if ($TMPL->fetch_param('paginate_type') == 'field')
			{ 
				if (preg_match("/".LD."multi_field\=[\"'](.+?)[\"']".RD."/s", $TMPL->tagdata, $mmatch))
				{ 
					$this->multi_fields = $TMPL->fetch_simple_conditions($mmatch['1']);
					
					$this->field_pagination = TRUE;
				}
			}
			
			$this->paginate	= TRUE;
			$this->paginate_data	= $match['1'];
						
			$TMPL->tagdata = preg_replace("/".LD."paginate".RD.".+?".LD.SLASH."paginate".RD."/s", "", $TMPL->tagdata);
		}
	}
	// END
	
	
	// ----------------------------------------
    //  Add pagination data to result
    // ----------------------------------------
    
    function add_pagination_data()
    {
    		global $TMPL;
    	
        if ($this->paginate == TRUE)
        {
			$this->paginate_data =& str_replace(LD.'current_page'.RD, 		$this->current_page, 		$this->paginate_data);
			$this->paginate_data =& str_replace(LD.'total_pages'.RD,			$this->total_pages,  		$this->paginate_data);
			$this->paginate_data =& str_replace(LD.'pagination_links'.RD,		$this->pagination_links,		$this->paginate_data);
        	
        	if (preg_match("/".LD."if previous_page".RD."(.+?)".LD.SLASH."if".RD."/s", $this->paginate_data, $match))
        	{
        		if ($this->page_previous == '')
        		{
        			 $this->paginate_data =& preg_replace("/".LD."if previous_page".RD.".+?".LD.SLASH."if".RD."/s", '', $this->paginate_data);
        		}
        		else
        		{
				$match['1'] = preg_replace("/".LD.'path.*?'.RD."/", 	$this->page_previous, $match['1']);
				$match['1'] = preg_replace("/".LD.'auto_path'.RD."/",	$this->page_previous, $match['1']);
			
				$this->paginate_data =& str_replace($match['0'],	$match['1'], $this->paginate_data);
			}
        	}
        	
        	
        	if (preg_match("/".LD."if next_page".RD."(.+?)".LD.SLASH."if".RD."/s", $this->paginate_data, $match))
        	{
        		if ($this->page_next == '')
        		{
        			 $this->paginate_data =& preg_replace("/".LD."if next_page".RD.".+?".LD.SLASH."if".RD."/s", '', $this->paginate_data);
        		}
        		else
        		{
				$match['1'] = preg_replace("/".LD.'path.*?'.RD."/", 	$this->page_next, $match['1']);
				$match['1'] = preg_replace("/".LD.'auto_path'.RD."/",	$this->page_next, $match['1']);
			
				$this->paginate_data =& str_replace($match['0'],	$match['1'], $this->paginate_data);
			}
        	}
                
			$position = ( ! $TMPL->fetch_param('paginate')) ? '' : $TMPL->fetch_param('paginate');
			
			switch ($position)
			{
				case "top"	: $this->return_data  = $this->paginate_data.$this->return_data;
					break;
				case "both"	: $this->return_data  = $this->paginate_data.$this->return_data.$this->paginate_data;
					break;
				default		: $this->return_data .= $this->paginate_data;
					break;
			}
        }	
    }
    // END
    
    
    // ----------------------------------------
    //  Fetch custom weblog field IDs
    // ----------------------------------------

    function fetch_custom_weblog_fields()
    {
        global $DB;
        
        // We'll first limit the query to only the field groups available
        // to the specific blog(s) for this account
        
        $group_id = '';
        
        if (USER_BLOG === FALSE)
        {
            $query = $DB->query("SELECT field_group FROM exp_weblogs WHERE is_user_blog = 'n'");
            
            foreach ($query->result as $row)
            {
                $group_id[] = $row['field_group'];
            }
        }
        else
        {
            $group_id = UB_FIELD_GRP;
        }     
        
        
        $sql = "SELECT field_id, field_name FROM exp_weblog_fields";
        
        if (is_array($group_id))
        {
            $sql .= " WHERE (";
            
            foreach ($group_id as $val)
            { 
                $sql .= " group_id = '$val' OR";
            }
            
            $sql = substr($sql, 0, -2).')';
        }
        else
        {
            $sql .= " WHERE group_id = '".$group_id."'";
        }
                
        $query = $DB->query($sql);
                
        foreach ($query->result as $row)
        {
            $this->cfields[$row['field_name']] = $row['field_id'];
        }
    }
    // END



    // ----------------------------------------
    //  Fetch custom member field IDs
    // ----------------------------------------

    function fetch_custom_member_fields()
    {
        global $DB;
        
        $query = $DB->query("SELECT m_field_id, m_field_name FROM exp_member_fields");
                
        foreach ($query->result as $row)
        { 
            $this->mfields[$row['m_field_name']] = $row['m_field_id'];
        }
    }
    // END`


    // ----------------------------------------
    //  Fetch categories
    // ----------------------------------------

    function fetch_categories()
    {
        global $DB;
        
        $sql = "SELECT exp_categories.cat_name, exp_categories.cat_id, exp_categories.cat_image,
                       exp_category_posts.cat_id, exp_category_posts.entry_id
                FROM   exp_categories, exp_category_posts
                WHERE  exp_categories.cat_id = exp_category_posts.cat_id
                AND (";
                
        $categories = array();
                
        foreach ($this->query->result as $row)
        {
            $sql .= " exp_category_posts.entry_id = '".$row['entry_id']."' OR "; 
            
            $categories[] = $row['entry_id'];
        }
        
        $sql = substr($sql, 0, -3).')';
        
        $sql .= " ORDER BY exp_categories.parent_id, exp_categories.cat_order";
        
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
            return;
        }
        
        foreach ($categories as $val)
        {
            $temp = array();
        
            foreach ($query->result as $row)
            {    
                if ($val == $row['entry_id'])
                {
                    $temp[] = array($row['cat_id'], $row['cat_name'], $row['cat_image']);
                }              
            }
            
            if (count($temp) == 0)
            {
                $temp = FALSE;
            }
        
            $this->categories[$val] = $temp;
        }        
    }
    // END
   
   

    // ----------------------------------------
    //   Build SQL query
    // ----------------------------------------

    function build_sql_query($qstring = '')
    {
        global $IN, $DB, $TMPL, $SESS, $LOC, $FNS, $REGX, $PREFS;
        
        $entry_id		= '';
        $year			= '';
        $month			= '';
        $day			= '';
        $qtitle			= '';
        $cat_id			= '';
        $corder			= '';
		$offset			=  0;
		$page_marker	= FALSE;
        $dynamic		= TRUE;
        
        $this->dynamic_sql = TRUE;
                 
        // ----------------------------------------------
        //  Is dynamic='off' set?
        // ----------------------------------------------
        
        // If so, we'll override all dynamicaly set variables
        
		if ($TMPL->fetch_param('dynamic') == 'off')
		{		
			$dynamic = FALSE;
		}  
				
        // ----------------------------------------------
        //  Parse the URL query string
        // ----------------------------------------------
        
        $this->uristr = $IN->URI;

        if ($qstring == '')
			$qstring = $IN->QSTR;
			
		$this->basepath = $FNS->create_url($this->uristr, 1);


		if ($qstring != '')
		{		
			// --------------------------------------
			//  Do we have a pure ID number?
			// --------------------------------------
		
			if (is_numeric($qstring) AND $dynamic)
			{
				$entry_id = &$qstring;
			}
			else
			{
			
				// --------------------------------------
				//  Parse day
				// --------------------------------------
				
				if (preg_match("#\d{4}/\d{2}/(\d{2})#", $qstring, $match) AND $dynamic)
				{											
					$partial = substr($match['0'], 0, -3);
					
					if (preg_match("#(\d{4}/\d{2})#", $partial, $pmatch))
					{											
						$ex = explode('/', $pmatch['1']);
						
						$year =  $ex['0'];
						$month = $ex['1'];  
					}
					
					$day = $match['1'];
										
					$qstring = $REGX->trim_slashes(str_replace($match['0'], $partial, $qstring));
				}
				
				// --------------------------------------
				//  Parse /year/month/
				// --------------------------------------
			
				//$qstring = $REGX->trim_slashes($qstring);
							
				if (preg_match("#(\d{4}/\d{2})#", $qstring, $match) AND $dynamic)
				{											
					$ex = explode('/', $match['1']);
					
					$year	= $ex['0'];
					$month	= $ex['1'];
					
					
					$qstring = $REGX->trim_slashes(str_replace($match['1'], '', $qstring));
	
					// Removed this in order to allow archive pagination
					// $this->paginate = FALSE;
				}
				
				// --------------------------------------
				//  Parse ID indicator
				// --------------------------------------

				if (preg_match("#^(\d+)(.*)#", $qstring, $match) AND $dynamic)
				{
					$seg = ( ! isset($match['2'])) ? '' : $match['2'];
				
					if (substr($seg, 0, 1) == "/" OR $seg == '')
					{
						$entry_id = $match['1'];	
						$qstring = $REGX->trim_slashes(preg_replace("#^".$match['1']."#", '', $qstring));
					}
				}
				

				// --------------------------------------
				//  Parse page number
				// --------------------------------------
				
				if (preg_match("#^P(\d+)|/P(\d+)#", $qstring, $match))
				{					
					$this->p_page = (isset($match['2'])) ? $match['2'] : $match['1'];	
						
					$this->basepath = $FNS->remove_double_slashes(str_replace($match['0'], '', $this->basepath));
							
					$this->uristr  = $FNS->remove_double_slashes(str_replace($match['0'], '', $this->uristr));
					
					$qstring = $REGX->trim_slashes(str_replace($match['0'], '', $qstring));
					
					$page_marker = TRUE;
				}

				// --------------------------------------
				//  Parse category indicator
				// --------------------------------------
				
				// Text version of the category
				
				if (preg_match("#^".$this->reserved_cat_segment."/#", $qstring, $match) AND $dynamic AND $TMPL->fetch_param('weblog'))
				{		
					$qstring = str_replace($this->reserved_cat_segment.'/', '', $qstring);
						
					$sql = "SELECT cat_group FROM exp_weblogs WHERE ";
					
					$sql .= (USER_BLOG !== FALSE) ? " weblog_id='".UB_BLOG_ID."'" : " blog_name='".$TMPL->fetch_param('weblog')."'";
						
					$query = $DB->query($sql);
					
					if ($query->num_rows == 1)
					{
					
					
						$result = $DB->query("SELECT cat_id FROM exp_categories WHERE cat_name='$qstring' AND group_id='{$query->row['cat_group']}'");
					
						if ($result->num_rows == 1)
						{
							$qstring = 'C'.$result->row['cat_id'];
						}
					}
				}

				// Numeric version of the category

				if (preg_match("#^C(\d+)#", $qstring, $match) AND $dynamic)
				{		
					$cat_id = $match['1'];	
														
					$qstring = $REGX->trim_slashes(str_replace($match['0'], '', $qstring));
				}
				
				// --------------------------------------
				//  Remove "N" 
				// --------------------------------------
				
				// The recent comments feature uses "N" as the URL indicator
				// It needs to be removed if presenst

				if (preg_match("#^N(\d+)|/N(\d+)#", $qstring, $match))
				{					
					$this->uristr  = $FNS->remove_double_slashes(str_replace($match['0'], '', $this->uristr));
					
					$qstring = $REGX->trim_slashes(str_replace($match['0'], '', $qstring));
				}
										
				// --------------------------------------
				//  Parse URL title
				// --------------------------------------

				if ($cat_id == '' AND $year == '')
				{
					$sql = "SELECT count(*) AS count 
							FROM  exp_weblog_titles, exp_weblogs 
							WHERE exp_weblog_titles.weblog_id = exp_weblogs.weblog_id
							AND   exp_weblog_titles.url_title = '".$DB->escape_str($qstring)."'";
					
					if (USER_BLOG !== FALSE)
					{
						$sql .= " AND exp_weblogs.weblog_id = '".UB_BLOG_ID."'";
					}
					else
					{
						$sql .= " AND exp_weblogs.is_user_blog = 'n'";
					}
									
					$query = $DB->query($sql);
					
					if ($query->row['count'] > 0)
					{
						$qtitle = &$qstring;
					}
					else
					{
						$qtitle = '';
					}
				}
			}
		}
		        
		    		        
        // ----------------------------------------------
        //  Entry ID number
        // ---------------------------------------------- 
        
        // If the "entry ID" was hard-coded, use it instead of
        // using the dynamically set one above
        
		if ($TMPL->fetch_param('entry_id'))
		{
			$entry_id = $TMPL->fetch_param('entry_id');
		}    

        // ----------------------------------------------
        //  Assing the order variables
        // ----------------------------------------------
		
		$order  =& $TMPL->fetch_param('orderby');
		$sort   =& $TMPL->fetch_param('sort');
		$sticky =& $TMPL->fetch_param('sticky');
				
		if ($sort == FALSE || ($sort != 'asc' AND $sort != 'desc'))
		{
			$sort = "desc";
		}
					
		$base_orders = array('random', 'date', 'title', 'url_title', 'edit_date', 'comment_total', 'username', 'screen_name', 'most_recent_comment', 'expiration_date');
		
		if ( ! in_array($order, $base_orders))
		{
			$corder = '';
			
			if (FALSE !== $order)
			{
				if (isset($this->cfields[$order]))
				{
					$corder = $this->cfields[$order];
				
					$order = 'custom_field';
				}     
			}
		}

        // ----------------------------------------------
        //  Build the master SQL query
        // ----------------------------------------------
		
		$sql_a = "SELECT ";
		
		$sql_b = ($TMPL->fetch_param('category') || $cat_id != '' || $order == 'random') ? "DISTINCT(exp_weblog_titles.entry_id) " : "exp_weblog_titles.entry_id ";
				
		if ($this->field_pagination == TRUE)
		{
			$sql_b .= ",exp_weblog_data.* ";
		}

		$sql_c = "COUNT(exp_weblog_titles.entry_id) AS count ";
		
		$sql = "FROM exp_weblog_titles
				LEFT JOIN exp_weblogs ON exp_weblog_titles.weblog_id = exp_weblogs.weblog_id ";
				
		if ($this->field_pagination == TRUE)
		{
			$sql .= "LEFT JOIN exp_weblog_data ON exp_weblog_titles.entry_id = exp_weblog_data.entry_id ";
		}
						
		if ($order == 'custom_field')
		{
			$sql .= "LEFT JOIN exp_weblog_data ON exp_weblog_titles.entry_id = exp_weblog_data.entry_id ";
		}
		
		$sql .= "LEFT JOIN exp_members ON exp_members.member_id = exp_weblog_titles.author_id ";
				
						  
        if ($TMPL->fetch_param('category') || $cat_id != '')                      
        {
			$sql .= "INNER JOIN exp_category_posts ON exp_weblog_titles.entry_id = exp_category_posts.entry_id
					 INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
        }
        
        // THT: Added by Vinay on 6/13/04 to support 'emailname' parameter
        if ($TMPL->fetch_param('emailname'))
        {
			$sql .= "LEFT JOIN exp_member_data ON exp_members.member_id = exp_member_data.member_id ";
        }
        // End of Vinay's addition
        
        // THT: Added by Vinay on 3/8/05 to support 'columnname' parameter
        if ($TMPL->fetch_param('columnname'))
        {
			$sql .= "LEFT JOIN exp_weblog_data ON exp_weblog_titles.entry_id = exp_weblog_data.entry_id ";
        }
        // End of Vinay's addition
        
        $sql .= "WHERE exp_weblog_titles.entry_id !='' ";

        // ----------------------------------------------
        // We only select entries that have not expired 
        // ----------------------------------------------
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		        
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
			$sql .= " AND exp_weblog_titles.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {        
			$sql .= " AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$timestamp.") ";
        }
        
        // ----------------------------------------------
        //  Limit query by post ID for individual entries
        // ----------------------------------------------
         
        if ($entry_id != '')
        {           
        	$sql .= (ereg("\|", $entry_id)) ? $FNS->sql_andor_string($entry_id, 'exp_weblog_titles.entry_id') : "AND exp_weblog_titles.entry_id = '$entry_id' ";
        }
        
        // ----------------------------------------------
        //  Limit query by entry_id range
        // ----------------------------------------------
                
        if ($entry_id_from = $TMPL->fetch_param('entry_id_from'))
        {
            $sql .= "AND exp_weblog_titles.entry_id >= '$entry_id_from' ";
        }
        
        if ($entry_id_to = $TMPL->fetch_param('entry_id_to'))
        {
            $sql .= "AND exp_weblog_titles.entry_id <= '$entry_id_to' ";
        }
        
        // ----------------------------------------------
        //  Exclude an individual entry
        // ----------------------------------------------

		if ($not_entry_id = $TMPL->fetch_param('not_entry_id'))
		{
			$sql .= ( ! is_numeric($not_entry_id)) 
					? "AND exp_weblog_titles.url_title != '{$not_entry_id}' " 
					: "AND exp_weblog_titles.entry_id  != '{$not_entry_id}' ";
		}

        // ----------------------------------------------
        // Limit to/exclude specific weblogs
        // ----------------------------------------------
    
        if (USER_BLOG !== FALSE)
        {
            // If it's a "user blog" we limit to only their assigned blog
        
            $sql .= "AND exp_weblogs.weblog_id = '".UB_BLOG_ID."' ";
        }
        else
        {
            $sql .= "AND exp_weblogs.is_user_blog = 'n' ";
        
            if ($weblog = $TMPL->fetch_param('weblog'))
            {
                $xql = "SELECT weblog_id FROM exp_weblogs WHERE ";
            
                $str = $FNS->sql_andor_string($weblog, 'blog_name');
                
                if (substr($str, 0, 3) == 'AND')
                    $str = substr($str, 3);
                
                $xql .= $str;            
                    
                $query = $DB->query($xql);
                
                if ($query->num_rows == 0)
                {
					return $TMPL->no_results();
                }
                else
                {
                    if ($query->num_rows == 1)
                    {
                        $sql .= "AND exp_weblog_titles.weblog_id = '".$query->row['weblog_id']."' ";
                    }
                    else
                    {
                        $sql .= "AND (";
                        
                        foreach ($query->result as $row)
                        {
                            $sql .= "exp_weblog_titles.weblog_id = '".$row['weblog_id']."' OR ";
                        }
                        
                        $sql = substr($sql, 0, - 3);
                        
                        $sql .= ") ";
                    }
                }
            }
        }

        // ----------------------------------------------------
        //  Limit query by date range given in tag parameters
        // ----------------------------------------------------

        if ($TMPL->fetch_param('start_on'))
            $sql .= "AND exp_weblog_titles.entry_date >= '".$LOC->convert_human_date_to_gmt($TMPL->fetch_param('start_on'))."' ";

        if ($TMPL->fetch_param('stop_before'))
            $sql .= "AND exp_weblog_titles.entry_date < '".$LOC->convert_human_date_to_gmt($TMPL->fetch_param('stop_before'))."' ";
                

        // -----------------------------------------------------
        //  Limit query by date contained in tag parameters
        // -----------------------------------------------------
			        
        if ($TMPL->fetch_param('year') || $TMPL->fetch_param('month') || $TMPL->fetch_param('day'))
        {
            $year	= ( ! $TMPL->fetch_param('year')) 	? date('Y') : $TMPL->fetch_param('year');
            $smonth	= ( ! $TMPL->fetch_param('month'))	? '01' : $TMPL->fetch_param('month');
            $emonth	= ( ! $TMPL->fetch_param('month'))	? '12':  $TMPL->fetch_param('month');
            $day	= ( ! $TMPL->fetch_param('day'))	? '' : $TMPL->fetch_param('day');
            
            if ($day != '' AND ! $TMPL->fetch_param('month'))
            {
				$smonth = date('m');
				$emonth = date('m');
            }
            
            if (strlen($smonth) == 1) $smonth = '0'.$smonth;
            if (strlen($emonth) == 1) $emonth = '0'.$emonth;
        
			if ($day == '')
			{
				$sday = 1;
				$eday = $LOC->fetch_days_in_month($emonth, $year);
			}
			else
			{
				$sday = $day;
				$eday = $day;
			}
			
			$stime = $LOC->set_gmt(mktime(0, 0, 0, $smonth, $sday, $year));
			$etime = $LOC->set_gmt(mktime(23, 59, 59, $emonth, $eday, $year));  
			
			$sql .= " AND exp_weblog_titles.entry_date >= ".$stime." AND exp_weblog_titles.entry_date <= ".$etime." ";            
        }
        else
        {
            // ------------------------------------------------
            //  Limit query by date in URI: /2003/12/14/
            // -------------------------------------------------
            
            if ($year != '' AND $month != '' AND $dynamic == TRUE)
            {
            	if ($day == '')
            	{
            		$sday = 1;
            		$eday = $LOC->fetch_days_in_month($month, $year);
            	}
            	else
            	{
            		$sday = $day;
            		$eday = $day;
            	}
            	            	
				$stime = $LOC->set_gmt(mktime(0, 0, 0, $month, $sday, $year));
				$etime = $LOC->set_gmt(mktime(23, 59, 59, $month, $eday, $year)); 
				
// THT, Vinay 1/19/05
// There's a bug in EE's handling of daylight savings time
// They compensate for DST when they don't need to because
// the PHP built-ins do it automatically.  So I've commented
// out EE's DST adjustments below.
// See http://www.pmachine.com/forum/threads.php?id=17690_0_22_80_C
/*
				if (date("I", $LOC->now) AND ! date("I", $stime))
				{ 
					$stime -= 3600;            
				}
				elseif (date("I", $stime))
				{
					$stime += 3600;           
				}
*/		
				$stime += $LOC->set_localized_offset();
/*				
				if (date("I", $LOC->now) AND ! date("I", $etime))
				{ 
					$etime -= 3600;            
				}
				elseif (date("I", $etime))
				{
					$etime += 3600;           
				}
*/		
				$etime += $LOC->set_localized_offset();
// END THT mods
				
        			$sql .= " AND exp_weblog_titles.entry_date >= ".$stime." AND exp_weblog_titles.entry_date <= ".$etime." ";
            }
            else
            {
				$this->display_by =& $TMPL->fetch_param('display_by');

                $lim = ( ! $TMPL->fetch_param('limit')) ? '1' : $TMPL->fetch_param('limit');
                 
                // -------------------------------------------
                //  If display_by = "month"
                // -------------------------------------------                          
                 
                if ($this->display_by == 'month')
                {   
					// We need to run a query and fetch the distinct months in which there are entries
				
					$query = $DB->query("SELECT exp_weblog_titles.year, exp_weblog_titles.month ".$sql);
				
					$distinct = array();
                	
					if ($query->num_rows > 0)
					{
						foreach ($query->result as $row)
						{ 
							$distinct[] = $row['year'].$row['month'];
						}
						
						$distinct = array_unique($distinct);
						
						sort($distinct);
						
						if ($sort == 'desc')
						{
							$distinct = array_reverse($distinct);
						}
						
						$this->total_rows = count($distinct);
						
						$cur = ($this->p_page == '') ? 0 : $this->p_page;
						
						$distinct = array_slice($distinct, $cur, $lim);	
						
						$sql .= "AND (";
						
						foreach ($distinct as $val)
						{                	
							$sql .= "(exp_weblog_titles.year  = '".substr($val, 0, 4)."' AND exp_weblog_titles.month = '".substr($val, 4, 2)."') OR";
						}
						
						$sql = substr($sql, 0, -2).')';
                		}                    
                }
                
                
                // -------------------------------------------
                //  If display_by = "day"
                // -------------------------------------------                          
                
                elseif ($this->display_by == 'day')
                {   
					// We need to run a query and fetch the distinct days in which there are entries
				
					$query = $DB->query("SELECT exp_weblog_titles.year, exp_weblog_titles.month, exp_weblog_titles.day ".$sql);
				
					$distinct = array();
                	
					if ($query->num_rows > 0)
					{
						foreach ($query->result as $row)
						{ 
							$distinct[] = $row['year'].$row['month'].$row['day'];
						}
						
						$distinct = array_unique($distinct);
						
						sort($distinct);
						
						if ($sort == 'desc')
						{
							$distinct = array_reverse($distinct);
						}
						
						$this->total_rows = count($distinct);
						
						$cur = ($this->p_page == '') ? 0 : $this->p_page;
						
						$distinct = array_slice($distinct, $cur, $lim);	
						
						$sql .= "AND (";
						
						foreach ($distinct as $val)
						{                	
							$sql .= "(exp_weblog_titles.year  = '".substr($val, 0, 4)."' AND exp_weblog_titles.month = '".substr($val, 4, 2)."' AND exp_weblog_titles.day   = '".substr($val, 6)."' ) OR";
						}
						
						$sql = substr($sql, 0, -2).')';
                		}                    
                }
            }
        }
        
        
        // ----------------------------------------------
        //  Limit query "URL title"
        // ----------------------------------------------
         
        if ($qtitle != '' AND $dynamic)
        {    
			$sql .= "AND exp_weblog_titles.url_title = '".$DB->escape_str($qtitle)."' ";
        }
        
        // ----------------------------------------------
        //  Limit query by category
        // ----------------------------------------------
                
        if ($TMPL->fetch_param('category'))
        {
            $sql .= $FNS->sql_andor_string($TMPL->fetch_param('category'), 'exp_categories.cat_id')." ";
        }
        else
        {
            if ($cat_id != '' AND $dynamic)
            {           
                $sql .= " AND exp_categories.cat_id = '".$DB->escape_str($cat_id)."' ";
            }
        }
              
        // ----------------------------------------------
        // Limit to (or exclude) specific users
        // ----------------------------------------------
        
        if ($username = $TMPL->fetch_param('username'))
        {
            // Shows entries ONLY for currently logged in user
        
            if ($username == 'CURRENT_USER')
            {
                $sql .=  "AND exp_members.member_id = '".$SESS->userdata['member_id']."' ";
            }
            elseif ($username == 'NOT_CURRENT_USER')
            {
                $sql .=  "AND exp_members.member_id != '".$SESS->userdata['member_id']."' ";
            }
            else
            {                
                $sql .= $FNS->sql_andor_string($username, 'exp_members.username');
            }
        }
    
			// ----------------------------------------------
			// THT: Added by Vinay 3/13/04, to enable query by emailname
			// Modified on 6/13/04 to fix a bug (which I don't completely understand)
			// 1/15/05, Vinay: modified again to fix bugs
			// ----------------------------------------------
			
			if ($emailname = $TMPL->fetch_param('emailname'))
			{
			$sql .= str_replace("AND", "AND (", $FNS->sql_andor_string($emailname, 'exp_member_data.m_field_id_2'));
			$sql .= str_replace("AND", " ||", $FNS->sql_andor_string($emailname, 'exp_member_data.m_field_id_3')) . " ) ";
			}
			// End of Vinay's addition
			
			// ----------------------------------------------
			// THT: Added by Vinay 3/8/05, to enable query by columnname
			// ----------------------------------------------
			
			if ($columnname = $TMPL->fetch_param('columnname'))
			{
			$sql .= $FNS->sql_andor_string($columnname, 'exp_weblog_data.field_id_5') . " ";
			}
			// End of Vinay's addition
			
        // ----------------------------------------------
        // Add status declaration
        // ----------------------------------------------
                        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
			
			$sstr = $FNS->sql_andor_string($status, 'exp_weblog_titles.status');
			
			if ( ! eregi("'closed'", $sstr))
			{
				$sstr .= " AND exp_weblog_titles.status != 'closed' ";
			}
			
			$sql .= $sstr;
        }
        else
        {
            $sql .= "AND exp_weblog_titles.status = 'open' ";
        }
            
        // ----------------------------------------------
        //  Add Group ID clause
        // ----------------------------------------------
        
        if ($group_id = $TMPL->fetch_param('group_id'))
        {
            $sql .= $FNS->sql_andor_string($group_id, 'exp_members.group_id');
        }
              
        // --------------------------------------------------
        //  Build sorting clause
        // --------------------------------------------------
        
        // We'll assign this to a different variable since we
        // need to use this in two places
        
        $end = '';
	
		if (FALSE === $order)
		{
			if ($sticky == 'off')
			{
				$end .= "ORDER BY exp_weblog_titles.entry_date";
			}
			else
			{
				$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.entry_date";
			}
		}
		else
		{
			switch ($order)
			{
				case 'date' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.entry_date";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.entry_date";
								}
					break;
				case 'edit_date' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.edit_date";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.edit_date";
								}
					break;
				case 'expiration_date' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.expiration_date";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.expiration_date";
								}
					break;
				case 'title' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.title";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.title";
								}
					break;
				case 'url_title' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.url_title";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.url_title";
								}
					break;
				case 'comment_total' : 
				
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.comment_total {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.comment_total {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								
								$sort = FALSE;
					break;
				case 'most_recent_comment' : 
				
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.recent_comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.recent_comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								
								$sort = FALSE;
					break;
				case 'username' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_members.username";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_members.username";
								}
					break;
				case 'screen_name' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_members.screen_name";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_members.screen_name";
								}
					break;
				case 'custom_field' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY  exp_weblog_data.field_id_".$corder;
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc,  exp_weblog_data.field_id_".$corder;
								}
					break;
				case 'random' : 
								$end .= "ORDER BY rand()";  
								$sort = FALSE;
					break;
				default       : $end .= "ORDER BY exp_weblog_titles.entry_date";
					break;
			}                    
		}
		
		if ($sort == 'asc' || $sort == 'desc')
		{
			$end .= " $sort";
		}

		// ----------------------------------------
		//  Determine the row limits
		// ----------------------------------------
		// Even thouth we don't use the LIMIT clause until the end,
		// we need it to help create our pagination links so we'll
		// set it here
        
		if ($cat_id  != '' AND $TMPL->fetch_param('cat_limit'))
		{
			$this->p_limit = $TMPL->fetch_param('cat_limit');
		}
		elseif ($month != '' AND $TMPL->fetch_param('month_limit'))
		{
			$this->p_limit = $TMPL->fetch_param('month_limit');
		}
		else
		{
			$this->p_limit  = ( ! $TMPL->fetch_param('limit'))  ? $this->limit : $TMPL->fetch_param('limit');
		}

		// ----------------------------------------
		//  Do we need pagination?
		// ----------------------------------------
		
		// We'll run the query to find out

		if ($this->paginate == TRUE)
		{		
			if ($this->field_pagination == FALSE)
			{
				$this->pager_sql = $sql_a.$sql_c.$sql;
			
				$query = $DB->query($this->pager_sql);
				
				$total = $query->row['count'];
				$this->create_pagination($total);
			}
			else
			{
				$this->pager_sql = $sql_a.$sql_b.$sql;
				
				$query = $DB->query($this->pager_sql);
				
				$total = $query->num_rows;
				$this->create_pagination($total, $query);
				
				if ($PREFS->ini('enable_sql_caching') == 'y')
				{			
					$this->save_cache($this->pager_sql, 'pagination_query');
					$this->save_cache('1', 'field_pagination');
				}
			}
					
			if ($PREFS->ini('enable_sql_caching') == 'y')
			{			
				$this->save_cache($total, 'pagination_count');
			}
		}
               
        // ----------------------------------------------
        //  Add Limits to query
        // ----------------------------------------------
	
		$sql .= $end;
		
		$offset = ( ! $TMPL->fetch_param('offset')) ? '0' : $TMPL->fetch_param('offset');
	
		if ($this->paginate == FALSE)
			$this->p_page = 0;

		if ($this->display_by == '')
		{ 
			// Removed in order to allow archive pagination
			//if (($year == '' AND $month == '' AND $page_marker == FALSE AND $this->p_limit != '') || ($page_marker == TRUE AND $this->field_pagination != TRUE))

			if (($page_marker == FALSE AND $this->p_limit != '') || ($page_marker == TRUE AND $this->field_pagination != TRUE))
			{			
				$sql .= ($this->p_page == '') ? " LIMIT ".$offset.', '.$this->p_limit : " LIMIT ".$this->p_page.', '.$this->p_limit;  
			}
			elseif ($entry_id == '' AND $qtitle == '')
			{ 
				$sql .= ($this->p_page == '') ? " LIMIT ".$this->limit : " LIMIT ".$this->p_page.', '.$this->limit;
			}
		}
		else
		{
			if ($offset != 0)
			{
				$sql .= ($this->p_page == '') ? " LIMIT ".$offset.', '.$this->p_limit : " LIMIT ".$this->p_page.', '.$this->p_limit;  
			}
		}
                        
        // ----------------------------------------------
        //  Fetch the entry_id numbers
        // ----------------------------------------------
        
		$query = $DB->query($sql_a.$sql_b.$sql);        
        
        if ($query->num_rows == 0)
        {
			$this->sql = '';
			return;
        }
        		        
        // ----------------------------------------------
        //  Build the full SQL query
        // ----------------------------------------------
        
        $this->sql = "SELECT ";

        if ($TMPL->fetch_param('category') || $cat_id != '')                      
        {
        	// Using DISTINCT like this is bogus but since
        	// FULL OUTER JOINs are not supported in older versions
        	// of MySQL it's our only choice
        
			$this->sql .= " DISTINCT(exp_weblog_titles.entry_id), ";
        }
        
        // DO NOT CHANGE THE ORDER
        // The exp_member_data table needs to be called before the exp_members table.
	
		$this->sql .= " exp_weblog_titles.entry_id, exp_weblog_titles.weblog_id, exp_weblog_titles.author_id, exp_weblog_titles.ip_address, exp_weblog_titles.title, exp_weblog_titles.url_title, exp_weblog_titles.status, exp_weblog_titles.allow_comments, exp_weblog_titles.allow_trackbacks, exp_weblog_titles.sticky, exp_weblog_titles.entry_date, exp_weblog_titles.year, exp_weblog_titles.month, exp_weblog_titles.day, exp_weblog_titles.entry_date, exp_weblog_titles.edit_date, exp_weblog_titles.expiration_date, exp_weblog_titles.recent_comment_date, exp_weblog_titles.comment_total, exp_weblog_titles.trackback_total, exp_weblog_titles.sent_trackbacks, exp_weblog_titles.recent_trackback_date,
						exp_weblogs.blog_title, exp_weblogs.blog_url, exp_weblogs.comment_url, exp_weblogs.tb_return_url, exp_weblogs.comment_moderate, exp_weblogs.weblog_html_formatting, exp_weblogs.weblog_allow_img_urls, exp_weblogs.weblog_auto_link_urls, exp_weblogs.enable_trackbacks, exp_weblogs.trackback_field, exp_weblogs.trackback_use_captcha,
						exp_member_data.*,
						exp_members.username, exp_members.email, exp_members.url, exp_members.screen_name, exp_members.location, exp_members.occupation, exp_members.interests, exp_members.aol_im, exp_members.yahoo_im, exp_members.msn_im, exp_members.icq, exp_members.group_id, exp_members.member_id, exp_members.bday_d, exp_members.bday_m, exp_members.bday_y, exp_members.bio,
						exp_weblog_data.*
				FROM exp_weblog_titles
				LEFT JOIN exp_weblogs ON exp_weblog_titles.weblog_id = exp_weblogs.weblog_id 
				LEFT JOIN exp_weblog_data ON exp_weblog_titles.entry_id = exp_weblog_data.entry_id 
				LEFT JOIN exp_members ON exp_members.member_id = exp_weblog_titles.author_id 
				LEFT JOIN exp_member_data ON exp_member_data.member_id = exp_members.member_id ";
                      
        if ($TMPL->fetch_param('category') || $cat_id != '')                      
        {
            $this->sql .= "INNER JOIN exp_category_posts ON exp_weblog_titles.entry_id = exp_category_posts.entry_id
                           INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
        }
        
        $this->sql .= "WHERE exp_weblog_titles.entry_id IN (";
        
        $entries = array();
        
        // Build ID numbers (checking for duplicates)
        
        foreach ($query->result as $row)
        {        	
			if ( ! isset($entries[$row['entry_id']]))
			{
				$entries[$row['entry_id']] = 'y';
			}
			else
			{
				continue;
			}
        	
        		$this->sql .= $row['entry_id'].',';
        }
        
		$this->sql = substr($this->sql, 0, -1).') '.$end;        
    }    
    // END




	// ----------------------------------------
	//  Create pagination
	// ----------------------------------------

	function create_pagination($count = 0, $query = '')
	{
		global $FNS, $TMPL, $IN;
		
		if ($this->paginate == TRUE)
		{			
			
			if ($this->basepath == '')
			{
				$this->basepath = $FNS->create_url($IN->URI, 1);					
				if (preg_match("#^P(\d+)|/P(\d+)#", $IN->QSTR, $match))
				{					
					$this->p_page = (isset($match['2'])) ? $match['2'] : $match['1'];	
					$this->basepath = $FNS->remove_double_slashes(str_replace($match['0'], '', $this->basepath));
				}
			}
		
			// ----------------------------------------
			//  Standard pagination - base values
			// ----------------------------------------
		
			if ($this->field_pagination == FALSE)
			{
				if ($this->display_by == '')
				{					
					if ($count == 0)
					{
						$this->sql = '';
						return;
					}
				
					$this->total_rows = $count;
				}
				
				if ($this->dynamic_sql == FALSE)
				{
					$cat_limit = FALSE;
					if ((preg_match("#^".$this->reserved_cat_segment."/#", $IN->URI) 
						AND $TMPL->fetch_param('dynamic') != 'off' 
						AND $TMPL->fetch_param('weblog'))
						|| (preg_match("#^C(\d+)#", $IN->URI, $match) AND $dynamic))
					{		
						$cat_limit = TRUE;
					}
					
					if ($cat_limit AND $TMPL->fetch_param('cat_limit'))
					{
						$this->p_limit = $TMPL->fetch_param('cat_limit');
					}
					else
					{
						$this->p_limit  = ( ! $TMPL->fetch_param('limit'))  ? $this->limit : $TMPL->fetch_param('limit');				
					}	
				}
				
				$this->p_page = ($this->p_page == '' || ($this->p_limit > 1 AND $this->p_page == 1)) ? 0 : $this->p_page;
				
				if ($this->p_page > $this->total_rows)
				{
					$this->p_page = 0;
				}
								
				$this->current_page = floor(($this->p_page / $this->p_limit) + 1);
				
				$this->total_pages = intval(floor($this->total_rows / $this->p_limit));
			}
			else
			{
				// ----------------------------------------
				//  Field pagination - base values
				// ----------------------------------------							
										
				if ($count == 0)
				{
					$this->sql = '';
					return;
				}
						
				$m_fields = array();
				
				foreach ($this->multi_fields as $val)
				{
					if (isset($this->cfields[$val]))
					{
						if (isset($query->row['field_id_'.$this->cfields[$val]]) AND $query->row['field_id_'.$this->cfields[$val]] != '')
						{ 
							$m_fields[] = $val;
						}
					}
				}
														
				$this->p_limit = 1;
				
				$this->total_rows = count($m_fields);

				$this->total_pages = $this->total_rows;
				
				if ($this->total_pages == 0)
					$this->total_pages = 1;
				
				$this->p_page = ($this->p_page == '') ? 0 : $this->p_page;
				
				if ($this->p_page > $this->total_rows)
				{
					$this->p_page = 0;
				}
				
				$this->current_page = floor(($this->p_page / $this->p_limit) + 1);
				
				if (isset($m_fields[$this->p_page]))
				{
					$TMPL->tagdata = preg_replace("/".LD."multi_field\=[\"'].+?[\"']".RD."/s", LD.$m_fields[$this->p_page].RD, $TMPL->tagdata);
				
					$TMPL->var_single[$m_fields[$this->p_page]] = $m_fields[$this->p_page];
				}
			}
					
			// ----------------------------------------
			//  Create the pagination
			// ----------------------------------------
			
			if ($this->total_rows % $this->p_limit) 
			{
				$this->total_pages++;
			}	
			
			if ($this->total_rows > $this->p_limit)
			{
				if ( ! class_exists('Paginate'))
				{
					require PATH_CORE.'core.paginate'.EXT;
				}
				
				$PGR = new Paginate();
				
				if ( ! ereg(SELF, $this->basepath))
				{
					$this->basepath .= SELF.'/';
				}
													
				$first_url = (ereg("\.php/$", $this->basepath)) ? substr($this->basepath, 0, -1) : $this->basepath;
				
				$PGR->first_url 	= $first_url;
				$PGR->path			= $this->basepath;
				$PGR->prefix		= 'P';
				$PGR->total_count 	= $this->total_rows;
				$PGR->per_page		= $this->p_limit;
				$PGR->cur_page		= $this->p_page;

				$this->pagination_links = $PGR->show_links();
				
				if ((($this->total_pages * $this->p_limit) - $this->p_limit) > $this->p_page)
				{
					$this->page_next = $this->basepath.'P'.($this->p_page + $this->p_limit).'/';
				}
				
				if (($this->p_page - $this->p_limit ) >= 0) 
				{						
					$this->page_previous = $this->basepath.'P'.($this->p_page - $this->p_limit).'/';
				}
			}
			else
			{
				$this->p_page = '';
			}
		}
	}
	// END
	


    // ----------------------------------------
    //   Parse weblog entries
    // ----------------------------------------

    function parse_weblog_entries()
    {
        global $IN, $DB, $TMPL, $FNS, $SESS, $LOC, $PREFS, $REGX;
        
        $switch = array();
        
        // ----------------------------------------
        //  Set default date header variables
        // ----------------------------------------

        $heading_date_hourly  = 0;
        $heading_flag_hourly  = 0;
        $heading_flag_weekly  = 1;
        $heading_date_daily   = 0;
        $heading_flag_daily   = 0;
        $heading_date_monthly = 0;
        $heading_flag_monthly = 0;
        $heading_date_yearly  = 0;
        $heading_flag_yearly  = 0;
                
        // ----------------------------------------
        //  Fetch the "category chunk"
        // ----------------------------------------
        
        // We'll grab the category data now to avoid processing cycles in the foreach loop below
        
        $cat_chunk = array();
        
        if (preg_match_all("/".LD."categories(.*?)".RD."(.*?)".LD.SLASH.'categories'.RD."/s", $TMPL->tagdata, $matches))
        {
			for ($j = 0; $j < count($matches['0']); $j++)
			{
				$cat_chunk[] = array($matches['2'][$j], $TMPL->assign_parameters($matches['1'][$j]));
			}
      	}
      	
      	
        // ----------------------------------------
        //  Fetch all the date-related variables
        // ----------------------------------------
        
        $entry_date 		= array();
        $gmt_date 			= array();
        $gmt_entry_date		= array();
        $edit_date 			= array();
        $gmt_edit_date		= array();
        $expiration_date	= array();
        
        // We do this here to avoid processing cycles in the foreach loop
        
        $date_vars = array('entry_date', 'gmt_date', 'gmt_entry_date', 'edit_date', 'gmt_edit_date', 'expiration_date', 'recent_comment_date');
                
		foreach ($date_vars as $val)
		{					
			if (preg_match_all("/".LD.$val."\s+format=[\"'](.*?)[\"']".RD."/s", $TMPL->tagdata, $matches))
			{
				for ($j = 0; $j < count($matches['0']); $j++)
				{
					$matches['0'][$j] = str_replace(LD, '', $matches['0'][$j]);
					$matches['0'][$j] = str_replace(RD, '', $matches['0'][$j]);
					
					switch ($val)
					{
						case 'entry_date' 			: $entry_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'gmt_date'				: $gmt_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'gmt_entry_date'		: $gmt_entry_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'edit_date' 			: $edit_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'gmt_edit_date'		: $gmt_edit_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'expiration_date' 		: $expiration_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
						case 'recent_comment_date' 	: $recent_comment_date[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
							break;
					}
				}
			}
		}
      	
      	
        // ----------------------------------------
        //  "Search by Member" link
        // ----------------------------------------
		// We use this with the {member_search_path} variable
		
		$result_path = (preg_match("/".LD."member_search_path\s*=(.*?)".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : 'search/results';
		$result_path =& str_replace("\"", "", $result_path);
		$result_path =& str_replace("'",  "", $result_path);
		
		$qs = ($PREFS->ini('force_query_string') == 'y') ? '' : '?';        
		$search_link = $FNS->fetch_site_index(0, 0).$qs.'ACT='.$FNS->fetch_action_id('Search', 'do_search').'&amp;result_path='.$result_path.'&amp;fetch_posts_by=';
      	
        // ----------------------------------------
        //  Start the main processing loop
        // ----------------------------------------
        
        $tb_captcha = TRUE;

        foreach ($this->query->result as $row)
        {        
            // Fetch the tag block containing the variables that need to be parsed
        
            $tagdata =& $TMPL->tagdata;
                        
            // ----------------------------------------
            //   Parse conditional pairs
            // ----------------------------------------
			// Array prototype:
			// offset 0: the full opening tag sans delimiters:  if extended
			// offset 1: the compelte conditional chunk
			// offset 2: the inner conditional chunk
			// offset 3: the field name (optional)
            

            foreach ($TMPL->var_cond as $val)
            {
				// ----------------------------------------
				//   {if LOGGED_IN}
				// ----------------------------------------
			
				if ($val['0'] == "if logged_in")
				{					
					$tagdata =& str_replace($val['1'], (($SESS->userdata['member_id'] == 0) ? '' : $val['2']), $tagdata); 
				}
				
				// ----------------------------------------
				//   {if logged_out}
				// ----------------------------------------
	
				if ($val['0'] == "if logged_out")
				{					
					$tagdata =& str_replace($val['1'], (($SESS->userdata['member_id'] != 0) ? '' : $val['2']), $tagdata);                 
				}
                
                // ----------------------------------------
                //   {if allow_comments}
                // ----------------------------------------
                
				if ($val['0'] == "if allow_comments")
                {                					
					$tagdata =& str_replace($val['1'], (($row['allow_comments'] == 'n') ? '' : $val['2']), $tagdata);                 
                }
                
                // ----------------------------------------
                //   {if allow_trackbacks}
                // ----------------------------------------
                
				if ($val['0'] == "if allow_trackbacks")
                {   					
					$tagdata =& str_replace($val['1'], (($row['allow_trackbacks'] == 'n') ? '' : $val['2']), $tagdata);                 
                }
                
                
                // ----------------------------------------
                //   {if comment_tb_total}
                // ----------------------------------------
                
				if ($val['0'] == "if comment_tb_total")
                {                   					
					$tagdata =& str_replace($val['1'], ((($row['comment_total'] + $row['trackback_total']) == 0) ? '' : $val['2']), $tagdata);                 
                }                
                
                
                // ----------------------------------------
                //   Conditional statements
                // ----------------------------------------
                
                // The $val['0'] variable contains the full contitional statement.
                // For example: if username != 'joe'
                
                // Prep the conditional
                                
                $cond = $TMPL->prep_conditional($val['0']);
                
                $lcond	= substr($cond, 0, strpos($cond, ' '));
                $rcond	= substr($cond, strpos($cond, ' '));
                
				// ----------------------------------------
				//  Parse conditions in standard fields
				// ----------------------------------------
								
				if ( isset($row[$val['3']]))
				{  
					$lcond =& str_replace($val['3'], "\$row['".$val['3']."']", $lcond);
					
					$cond = $lcond.' '.$rcond;
					  
					$cond =& str_replace("\|", "|", $cond);

					eval("\$result = ".$cond.";");
										
					if ($result)
					{
						$tagdata =& str_replace($val['1'], $val['2'], $tagdata);                 
					}
					else
					{
						$tagdata =& str_replace($val['1'], '', $tagdata);                 
					}   
				}
				else
				{  
					// ------------------------------------------
					//  Parse conditions in custom weblog fields
					// ------------------------------------------
									
					if (isset($this->cfields[$val['3']]))
					{
						if (isset($row['field_id_'.$this->cfields[$val['3']]]))
						{
							$v = $row['field_id_'.$this->cfields[$val['3']]];
										 
							$lcond =& str_replace($val['3'], "\$v", $lcond);
							
							$cond = $lcond.' '.$rcond;
							
							$cond =& str_replace("\|", "|", $cond);
									 
							eval("\$result = ".$cond.";");
														
							if ($result)
							{
								$tagdata =& str_replace($val['1'], $val['2'], $tagdata);                 
							}
							else
							{
								$tagdata =& str_replace($val['1'], '', $tagdata);                 
							}   
						}
					}
					// ------------------------------------------
					//  Parse conditions in custom member fields
					// ------------------------------------------

					elseif (isset($this->mfields[$val['3']]))
					{
						if (isset($row['m_field_id_'.$this->mfields[$val['3']]]))
						{
							$v = $row['m_field_id_'.$this->mfields[$val['3']]];
										 
							$lcond =& str_replace($val['3'], "\$v", $lcond);
							
							$cond = $lcond.' '.$rcond;
							
							$cond =& str_replace("\|", "|", $cond);
									 
							eval("\$result = ".$cond.";");
												
							if ($result)
							{
								$tagdata =& str_replace($val['1'], $val['2'], $tagdata);                 
							}
							else
							{
								$tagdata =& str_replace($val['1'], '', $tagdata);                 
							}   
						}
					}                        
				}
            }
            // END CONDITIONAL PAIRS
            
                                                
            // ----------------------------------------
            //   Parse Variable Pairs
            // ----------------------------------------

            foreach ($TMPL->var_pair as $key => $val)
            {     
            
                // ----------------------------------------
                //  parse categories
                // ----------------------------------------
                
                if (ereg("^categories", $key))
                {   
                    if (isset($this->categories[$row['entry_id']]) AND is_array($this->categories[$row['entry_id']]) AND count($cat_chunk) > 0)
                    {                    
						foreach ($cat_chunk as $catkey => $catval)
						{
							$cats = '';
													
							foreach ($this->categories[$row['entry_id']] as $k => $v)
							{    
								$temp = $catval['0'];
							   
								if (preg_match_all("#".LD."path=(.+?)".RD."#", $temp, $matches))
								{
									foreach ($matches['1'] as $match)
									{																				
										if ($this->use_category_names == TRUE)
										{
											$temp = preg_replace("#".LD."path=.+?".RD."#", $FNS->remove_double_slashes($FNS->create_url($match).'/'.$this->reserved_cat_segment.'/'.$v['1'].'/'), $temp, 1);
										}
										else
										{
											$temp = preg_replace("#".LD."path=.+?".RD."#", $FNS->remove_double_slashes($FNS->create_url($match).'/C'.$v['0'].'/'), $temp, 1);
										}
									}
								}
								else
								{							
									$temp = preg_replace("#".LD."path=.+?".RD."#", $FNS->create_url("SITE_INDEX"), $temp);
								}
								
								$temp =& preg_replace("#".LD."category_id.*?".RD."#", $v['0'], $temp);	
								$temp =& preg_replace("#".LD."category_name.*?".RD."#", $v['1'], $temp);	
								$temp =& preg_replace("#".LD."category_image.*?".RD."#", $v['2'], $temp);	
										
								$cats .= $FNS->remove_double_slashes($temp);
							}
							
							$cats =& rtrim(str_replace("&#47;", "/", $cats));
							
							if (is_array($catval['1']) AND isset($catval['1']['backspace']))
							{
								$cats =& substr($cats, 0, - $catval['1']['backspace']);
							}							
							
							$cats =& str_replace("/", "&#47;", $cats);
							
							$tagdata =& preg_replace("/".LD.'categories'.".*?".RD."(.*?)".LD.SLASH.'categories'.RD."/s", $cats, $tagdata, 1);                        
						}
                    }
                    else
                    {
                        $tagdata =& $TMPL->delete_var_pairs($key, 'categories', $tagdata);
                    }
                }
            
                // ----------------------------------------
                //  parse date heading
                // ----------------------------------------
                
                if (ereg("^date_heading", $key))
                {   
                    // Set the display preference
                    
                    $display = (is_array($val) AND isset($val['display'])) ? $val['display'] : 'daily';
                    
                    // ----------------------------------------
                    //  Hourly header
                    // ----------------------------------------
                    
                    if ($display == 'hourly')
                    {
                        $heading_date_hourly =& date('YmdH', $LOC->set_localized_time($row['entry_date']));
                                                
                        if ($heading_date_hourly == $heading_flag_hourly)
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata =& $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_hourly = $heading_date_hourly;    
                        }
                    } 
   
                    // ----------------------------------------
                    //  Weekly header
                    // ----------------------------------------
                    
                    elseif ($display == 'weekly')
                    {                                 
                        if (date('w', $row['entry_date']) != $heading_flag_weekly)
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata =& $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        }
                    } 
   
                    // ----------------------------------------
                    //  Monthly header
                    // ----------------------------------------

                    elseif ($display == 'monthly')
                    {
                        $heading_date_monthly = date('Ym', $LOC->set_localized_time($row['entry_date']));
                                                
                        if ($heading_date_monthly == $heading_flag_monthly)
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata =& $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_monthly = $heading_date_monthly;    
                        }
                    } 
                    
                    // ----------------------------------------
                    //  Yearly header
                    // ----------------------------------------

                    elseif ($display == 'yearly')
                    {
                        $heading_date_yearly = date('Y', $LOC->set_localized_time($row['entry_date']));

                        if ($heading_date_yearly == $heading_flag_yearly)
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata =& $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_yearly = $heading_date_yearly;    
                        }
                    }
                    
                    // ----------------------------------------
                    //  Default (daily) header
                    // ----------------------------------------

                    else
                    {
                        $heading_date_daily = date('Ymd', $LOC->set_localized_time($row['entry_date']));

                        if ($heading_date_daily == $heading_flag_daily)
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata =& $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_daily = $heading_date_daily;    
                        }
                    }                    
                }
                // END DATE HEADING
                               
                
            }
            // END VARIABLE PAIRS
            
            
            
            // ----------------------------------------
            //   Parse "single" variables
            // ----------------------------------------

            foreach ($TMPL->var_single as $key => $val)
            {    
                // ------------------------------------------------
                //  parse simple conditionals: {body|more|summary}
                // ------------------------------------------------
                
                // Note:  This must happen first.
                
                if (ereg('\|', $key) AND is_array($val))
                {                
					foreach($val as $item)
					{
						// Basic fields
									
						if (isset($row[$item]) AND $row[$item] != "")
						{                    
							$tagdata =& $TMPL->swap_var_single($key, $row[$item], $tagdata);
							
							continue;
						}
		
						// Custom weblog fields
						
						if ( isset( $this->cfields[$item] ) AND isset( $row['field_id_'.$this->cfields[$item]] ) AND $row['field_id_'.$this->cfields[$item]] != "")
						{ 
							if ($row['enable_trackbacks'] == 'y')
								$this->display_tb_rdf = TRUE;
							
							if ($TMPL->fetch_param('rdf') != FALSE)
							{
								if ($TMPL->fetch_param('rdf') == "on")
									$this->display_tb_rdf = TRUE;
								elseif ($TMPL->fetch_param('rdf') == "off")
									$this->display_tb_rdf = FALSE;                    	
							}
								
							if ($this->display_tb_rdf == TRUE)
								$this->TYPE->encode_email = FALSE;		
																																	
							$entry =& $this->TYPE->parse_type( 
															   $row['field_id_'.$this->cfields[$item]], 
															   array(
																		'text_format'   => $row['field_ft_'.$this->cfields[$item]],
																		'html_format'   => $row['weblog_html_formatting'],
																		'auto_links'    => $row['weblog_auto_link_urls'],
																		'allow_img_url' => $row['weblog_allow_img_urls']
																	)
															 );
			
							$tagdata =& $TMPL->swap_var_single($key, $entry, $tagdata);                
															 
							continue;                                                               
						}
					}
					
					// Garbage collection
					$val = '';
					$tagdata =& $TMPL->swap_var_single($key, "", $tagdata);                
                }
            
            
				// ----------------------------------------
				//  parse {switch} variable
				// ----------------------------------------
				
				if (ereg("^switch", $key))
				{
					$sparam =& $TMPL->assign_parameters($key);
					
					$sw = '';

					if (isset($sparam['switch']))
					{
						$sopt = explode("|", $sparam['switch']);
						
						if (count($sopt) == 2)
						{
							if (isset($switch[$sparam['switch']]) AND $switch[$sparam['switch']] == $sopt['0'])
							{
								$switch[$sparam['switch']] = $sopt['1'];
								
								$sw = $sopt['1'];									
							}
							else
							{
								$switch[$sparam['switch']] = $sopt['0'];
								
								$sw = $sopt['0'];									
							}
						}
					}
					
					$tagdata =& $TMPL->swap_var_single($key, $sw, $tagdata);
				}
                                
                // ----------------------------------------
                //  parse entry date
                // ----------------------------------------
                
                if (isset($entry_date[$key]))
                {
					foreach ($entry_date[$key] as $dvar)
						$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], TRUE), $val);					

					$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);					
                }
            
                // ----------------------------------------
                //  Recent Comment Date
                // ----------------------------------------

                if (isset($recent_comment_date[$key]))
                {
                    if ($row['recent_comment_date'] != 0)
                    {
						foreach ($recent_comment_date[$key] as $dvar)
							$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['recent_comment_date'], TRUE), $val);					
	
						$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);	
                    }
                    else
                    {
                        $tagdata =& str_replace(LD.$key.RD, "", $tagdata); 
                    }                
                }
            
                // ----------------------------------------
                //  GMT date - entry date in GMT
                // ----------------------------------------
                
                if (isset($gmt_entry_date[$key]))
                {
					foreach ($gmt_entry_date[$key] as $dvar)
						$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], FALSE), $val);					

					$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);					
                }
                
                if (isset($gmt_date[$key]))
                {
					foreach ($gmt_date[$key] as $dvar)
						$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], FALSE), $val);					

					$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);					
                }
                                
                // ----------------------------------------
                //  parse "last edit" date
                // ----------------------------------------
                
                if (isset($edit_date[$key]))
                {
					foreach ($edit_date[$key] as $dvar)
						$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $LOC->timestamp_to_gmt($row['edit_date']), TRUE), $val);					

					$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);					
                }                
                
                // ----------------------------------------
                //  "last edit" date as GMT
                // ----------------------------------------
                
                if (isset($gmt_edit_date[$key]))
                {
					foreach ($gmt_edit_date[$key] as $dvar)
						$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $LOC->timestamp_to_gmt($row['edit_date']), FALSE), $val);					

					$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);					
                }

                
                // ----------------------------------------
                //  parse expiration date
                // ----------------------------------------
                
                if (isset($expiration_date[$key]))
                {
                    if ($row['expiration_date'] != 0)
                    {
						foreach ($expiration_date[$key] as $dvar)
							$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['expiration_date'], TRUE), $val);					
	
						$tagdata =& $TMPL->swap_var_single($key, $val, $tagdata);	
                    }
                    else
                    {
                        $tagdata =& str_replace(LD.$key.RD, "", $tagdata); 
                    }
                }                

              
                // ----------------------------------------
                //  parse profile path
                // ----------------------------------------
                                
                if (ereg("^profile_path", $key))
                {
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($FNS->extract_path($key).'/'.$row['member_id']), 
														$tagdata
													 );
                }
                                
                // ----------------------------------------
                //  {member_search_path}
                // ----------------------------------------                        
   
                if (ereg("^member_search_path", $key))
                {
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$search_link.urlencode($row['screen_name']), 
														$tagdata
													 );
                }


                // ----------------------------------------
                //  parse comment_path or trackback_path
                // ----------------------------------------
                
                if (ereg("^comment_path", $key) || ereg("^trackback_path", $key) || ereg("^entry_id_path", $key) )
                {                       
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($FNS->extract_path($key).'/'.$row['entry_id']), 
														$tagdata
													 );
                }


                // ----------------------------------------
                //  parse title permalink
                // ----------------------------------------
                
                if (ereg("^title_permalink", $key) || ereg("^url_title_path", $key))
                { 
					$path = ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX') ? $FNS->extract_path($key).'/'.$row['url_title'] : $row['url_title'];

					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($path, 1, 0), 
														$tagdata
													 );
                }
                
                // ----------------------------------------
                //  parse permalink
                // ----------------------------------------
                
                if (ereg("^permalink", $key))
                {                     
					$path = ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX') ? $FNS->extract_path($key).'/'.$row['entry_id'] : $row['entry_id'];
                
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($path, 1, 0), 
														$tagdata
													 );
                }
           
           
                // ----------------------------------------
                //  {comment_auto_path}
                // ----------------------------------------
                
                if ($key == "comment_auto_path")
                {           
                	$path = ($row['comment_url'] == '') ? $row['blog_url'] : $row['comment_url'];
                	
					$tagdata =& $TMPL->swap_var_single($key, $path, $tagdata);
                }
           
                // ----------------------------------------
                //  {comment_url_title_auto_path}
                // ----------------------------------------
                
                if ($key == "comment_url_title_auto_path")
                { 
                	$path = ($row['comment_url'] == '') ? $row['blog_url'] : $row['comment_url'];
                	
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$path.$row['url_title'].'/', 
														$tagdata
													 );
                }
      
                // ----------------------------------------
                //  {comment_entry_id_auto_path}
                // ----------------------------------------
                
                if ($key == "comment_entry_id_auto_path")
                {           
                	$path = ($row['comment_url'] == '') ? $row['blog_url'] : $row['comment_url'];
                	
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$path.$row['entry_id'].'/', 
														$tagdata
													 );
                }
      
      
      
                // ----------------------------------------
                //  {author}
                // ----------------------------------------
                
                if ($key == "author")
                {
                    $tagdata =& $TMPL->swap_var_single($val, ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'], $tagdata);
                }

                // ----------------------------------------
                //  {weblog}
                // ----------------------------------------
                
                if ($key == "weblog")
                {
                    $tagdata =& $TMPL->swap_var_single($val, $row['blog_title'], $tagdata);
                }
                
                // ----------------------------------------
                //  {relative_date}
                // ----------------------------------------
                
                if ($key == "relative_date")
                {
                    $tagdata =& $TMPL->swap_var_single($val, $LOC->format_timespan($LOC->now - $row['entry_date']), $tagdata);
                }

                // ----------------------------------------
                //  {trimmed_url} - used by Atom feeds
                // ----------------------------------------
                
                if ($key == "trimmed_url")
                {
					$blog_url = (isset($row['blog_url']) AND $row['blog_url'] != '') ? $row['blog_url'] : '';
				
					$blog_url =& str_replace('http://', '', $blog_url);
					$blog_url =& str_replace('www.', '', $blog_url);
					$blog_url =& current(explode("/", $blog_url));
                
                    $tagdata =& $TMPL->swap_var_single($val, $blog_url, $tagdata);
                }
                
                // ----------------------------------------
                //  {relative_url} - used by Atom feeds
                // ----------------------------------------
                
                if ($key == "relative_url")
                {
					$blog_url = (isset($row['blog_url']) AND $row['blog_url'] != '') ? $row['blog_url'] : '';
					$blog_url =& str_replace('http://', '', $blog_url);
                	
					if ($x = strpos($blog_url, "/"))
					{
						$blog_url =& substr($blog_url, $x + 1);
					}
					
					if (ereg("/$", $blog_url))
					{
						$blog_url =& substr($blog_url, 0, -1);
					}
					
                    $tagdata =& $TMPL->swap_var_single($val, $blog_url, $tagdata);
                }
                
                // ----------------------------------------
                //  {url_or_email}
                // ----------------------------------------
                
                if ($key == "url_or_email")
                {
                    $tagdata =& $TMPL->swap_var_single($val, ($row['url'] != '') ? $row['url'] : $this->TYPE->encode_email($row['email'], '', 0), $tagdata);
                }

                // ----------------------------------------
                //  {url_or_email_as_author}
                // ----------------------------------------
                
                if ($key == "url_or_email_as_author")
                {
                    $name = ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'];
                    
                    if ($row['url'] != '')
                    {
                        $tagdata =& $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$name."</a>", $tagdata);
                    }
                    else
                    {
                        $tagdata =& $TMPL->swap_var_single($val, $this->TYPE->encode_email($row['email'], $name), $tagdata);
                    }
                }
                
                
                // ----------------------------------------
                //  {url_or_email_as_link}
                // ----------------------------------------
                
                if ($key == "url_or_email_as_link")
                {                    
                    if ($row['url'] != '')
                    {
                        $tagdata =& $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$row['url']."</a>", $tagdata);
                    }
                    else
                    {                        
                        $tagdata =& $TMPL->swap_var_single($val, $this->TYPE->encode_email($row['email']), $tagdata);
                    }
                }
               
               
                // ----------------------------------------
                //  parse {comment_tb_total}
                // ----------------------------------------
                
                if (ereg("^comment_tb_total$", $key))
                {                        
                    $tagdata =& $TMPL->swap_var_single($val, ($row['comment_total'] + $row['trackback_total']), $tagdata);
                }
                
                    
                // ----------------------------------------
                //  parse basic fields (username, screen_name, etc.)
                // ----------------------------------------
                 
                if (isset($row[$val]))
                {                    
                    $tagdata =& $TMPL->swap_var_single($val, $row[$val], $tagdata);
                }
               
                    
                // ----------------------------------------
                //  parse custom weblog fields
                // ----------------------------------------
                                
                if ( isset( $this->cfields[$val] ) AND isset( $row['field_id_'.$this->cfields[$val]] ) )
                {       
					if ($row['enable_trackbacks'] == 'y')
						$this->display_tb_rdf = TRUE;
					
					if ($TMPL->fetch_param('rdf') != FALSE)
					{
						if ($TMPL->fetch_param('rdf') == "on")
							$this->display_tb_rdf = TRUE;
						elseif ($TMPL->fetch_param('rdf') == "off")
							$this->display_tb_rdf = FALSE;                    	
					}
					
					if ($this->display_tb_rdf == TRUE)
						$this->TYPE->encode_email = FALSE;		
					
					// This line of code fixes a very odd bug that happens when you place EE tags in weblog entries
					// For some inexplicable reason, we have to convert the tag to entities before sending it to 
					// the typography class below or else it tries to get parsed as a tag.  What is totally baffling
					// is that the typography class converts tags, so we shouldn't have to do it here.  I can't
					// figure out a solution, however.
					
					$row['field_id_'.$this->cfields[$val]] = $REGX->encode_ee_tags($row['field_id_'.$this->cfields[$val]]);
                                
                    // ----
                                                    
                    $entry =& $this->TYPE->parse_type( 
                                                        $row['field_id_'.$this->cfields[$val]], 
                                                        array(
                                                                'text_format'   => $row['field_ft_'.$this->cfields[$val]],
                                                                'html_format'   => $row['weblog_html_formatting'],
                                                                'auto_links'    => $row['weblog_auto_link_urls'],
                                                                'allow_img_url' => $row['weblog_allow_img_urls']
                                                              )
                                                      );
                                                                      
                    $tagdata =& $TMPL->swap_var_single($val, $entry, $tagdata);                
                }
                
                // ----------------------------------------
                //  parse custom member fields
                // ----------------------------------------
                
                if ( isset( $this->mfields[$val] ) AND isset( $row['m_field_id_'.$this->mfields[$val]] ) )
                {
                    $tagdata =& $TMPL->swap_var_single(
                                                        $val, 
                                                        $row['m_field_id_'.$this->mfields[$val]], 
                                                        $tagdata
                                                      );
                }
               

            }
            // END SINGLE VARIABLES 
               
               
               
            // ----------------------------------------
            // Compile trackback data
            // ----------------------------------------
                        
            if ($this->display_tb_rdf == TRUE)
            {
                $categories = '';
                
                if (isset($this->categories[$row['entry_id']]))
                {                    
                    if (is_array($this->categories[$row['entry_id']]))
                    {
                        foreach ($this->categories[$row['entry_id']] as $k => $v)
                        {
                            $categories .= $v['1'].',';
                        } 
                        
                        $categories =& substr($categories, 0, -1);                           
                    }
                }
                
                
				// ----------------------------------------
				//  Build Trackback RDF
				// ----------------------------------------
								
				if ($row['trackback_use_captcha'] == 'y' AND $tb_captcha == TRUE)
				{			
					$this->tb_captcha_hash = $FNS->random('alpha', 8);
					
					$DB->query("INSERT INTO exp_captcha (date, ip_address, word) VALUES (UNIX_TIMESTAMP(), '".$IN->IP."', '".$this->tb_captcha_hash."')");
					
					$this->tb_captcha_hash .= '/';
					
					$tb_captcha = FALSE;
				}
				
				$ret_url = ($row['tb_return_url'] == '') ? $row['blog_url'] : $row['tb_return_url'];
            
                $TB = array(
                             'about'        => $FNS->remove_double_slashes($ret_url.'/'.$row['url_title'].'/'),
                             'ping'         => $FNS->fetch_site_index(1, 0).'trackback/'.$row['entry_id'].'/'.$this->tb_captcha_hash,
                             'title'        => $REGX->xml_convert($row['title']),
                             'identifier'   => $FNS->remove_double_slashes($ret_url.'/'.$row['url_title'].'/'),
                             'subject'      => $REGX->xml_convert($categories),
                             'description'  => $this->TYPE->decode_pmcode($REGX->xml_convert($FNS->char_limiter((isset($row['field_id_'.$row['trackback_field']])) ? $row['field_id_'.$row['trackback_field']] : ''))),
                             'creator'      => $REGX->xml_convert(($row['screen_name'] != '') ? $row['screen_name'] : $row['username']),
                             'date'         => $LOC->set_human_time($row['entry_date'], 0, 1).' GMT'
                            );
            
                $tagdata .= $this->trackback_rdf($TB);    
                
                $this->display_tb_rdf = FALSE;        
            }
                        
            $this->return_data .= $tagdata;
            
        }
        // END FOREACH LOOP
        
        // Kill multi_field variable        
        $this->return_data =& preg_replace("/".LD."multi_field\=[\"'](.+?)[\"']".RD."/s", "", $this->return_data);
        
        // Do we have backspacing?
        // This can only be used when RDF data is not present.
        
		if ($back = $TMPL->fetch_param('backspace') AND $this->display_tb_rdf != TRUE)
		{
			if (is_numeric($back))
			{
				$this->return_data =& rtrim(str_replace("&#47;", "/", $this->return_data));
				$this->return_data =& substr($this->return_data, 0, - $back);
				$this->return_data =& str_replace("/", "&#47;", $this->return_data);
			}
		}		

    }
    // END



    // ----------------------------------------
    //  Weblog Info Tag
    // ----------------------------------------

    function info()
    {
        global $TMPL, $DB, $LANG;
        
        if ( ! $blog_name = $TMPL->fetch_param('weblog'))
        {
        		return '';
        }
        
        if (count($TMPL->var_single) == 0)
        {
        		return '';
        }
        
        $params = array(
        					'blog_title',
        					'blog_url',
        					'blog_description',
        					'blog_lang',
        					'blog_encoding'
        					);        
        
        $q = '';
        
		foreach ($TMPL->var_single as $val)
		{
			if (in_array($val, $params))
			{
				$q .= $val.',';
			}
		}
        
        $q = substr($q, 0, -1);
        
        if ($q == '')
        		return '';
        

		$sql = "SELECT ".$q." FROM exp_weblogs ";
				
		if (USER_BLOG !== FALSE)
		{
			$sql .= " WHERE exp_weblogs.weblog_id = '".UB_BLOG_ID."'";
		}
		else
		{
			$sql .= " WHERE exp_weblogs.is_user_blog = 'n'";
		
			if ($blog_name != '')
			{
				$sql .= " AND blog_name = '".$DB->escape_str($blog_name)."'";
			}
		}
				
		$query = $DB->query($sql);

		if ($query->num_rows != 1)
		{
			return '';
		}
		
		foreach ($query->row as $key => $val)
		{
			$TMPL->tagdata = str_replace(LD.$key.RD, $val, $TMPL->tagdata);
		}

		return $TMPL->tagdata;
	}
	// END
	


    // ----------------------------------------
    //  Weblog Name
    // ----------------------------------------

    function weblog_name()
    {
        global $TMPL, $DB, $LANG;

		$blog_name = $TMPL->fetch_param('weblog');
		
		if (isset($this->weblog_name[$blog_name]))
		{
			return $this->weblog_name[$blog_name];
		}

		$sql = "SELECT blog_title FROM exp_weblogs ";
				
		if (USER_BLOG !== FALSE)
		{
			$sql .= " WHERE exp_weblogs.weblog_id = '".UB_BLOG_ID."'";
		}
		else
		{
			$sql .= " WHERE exp_weblogs.is_user_blog = 'n'";
		
			if ($blog_name != '')
			{
				$sql .= " AND blog_name = '".$DB->escape_str($blog_name)."'";
			}
		}
				
		$query = $DB->query($sql);

		if ($query->num_rows == 1)
		{
			$this->weblog_name[$blog_name] = $query->row['blog_title'];
		
			return $query->row['blog_title'];
		}
		else
		{
			return '';
		}
	}
	// END
	
	

    // ----------------------------------------
    //  Weblog Categories
    // ----------------------------------------

    function categories()
    {
        global $TMPL, $LOC, $FNS, $REGX, $DB, $LANG;		
		
		if (USER_BLOG !== FALSE)
		{
		    $group_id = UB_CAT_GRP;
		}
		else
		{
            $sql = "SELECT cat_group FROM exp_weblogs";
		
            if ($weblog = $TMPL->fetch_param('weblog'))
            {
                $sql .= " WHERE blog_name = '".$DB->escape_str($weblog)."'";
		    }
		    
		    $query = $DB->query($sql);
		        
            if ($query->num_rows != 1)
            {
                return '';
            }
            
            $group_id = $query->row['cat_group'];
		}
			
		$parent_only = ($TMPL->fetch_param('parent_only') == 'yes') ? TRUE : FALSE;
		                        		
		$path = array();
		
		if (preg_match_all("#".LD."path(=.+?)".RD."#", $TMPL->tagdata, $matches)) 
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{			
				if ( ! isset($path[$matches['0'][$i]]))
				{
					$path[$matches['0'][$i]] = $FNS->create_url($FNS->extract_path($matches['1'][$i]));
				}
			}
		}
		                
		$str = '';
		
		if ($TMPL->fetch_param('style') == '' OR $TMPL->fetch_param('style') == 'nested')
        {
			$this->category_tree(
									array(
											'group_id'		=> $group_id, 
											'template'		=> $TMPL->tagdata, 
											'path'			=> $path, 
											'blog_array' 	=> '',
											'parent_only'	=> $parent_only,
											'show_empty'		=> $TMPL->fetch_param('show_empty')
										  )
								);
				
						
			if (count($this->category_list) > 0)
			{
				$i = 0;
			
				foreach ($this->category_list as $val)
				{
					$str .= $val;                    
				}
			}
		}
		else
		{
			$show_empty = $TMPL->fetch_param('show_empty');
		
			if ($show_empty == 'no')
			{	
				// First we'll grab all category ID numbers
			
				$query = $DB->query("SELECT cat_id, parent_id FROM exp_categories WHERE group_id ='$group_id' ORDER BY parent_id, cat_order");
				
				$all = array();
				
				// No categories exist?  Let's go home..
				if ($query->num_rows == 0)
					return false;
				
				foreach($query->result as $row)
				{
					$all[$row['cat_id']] = $row['parent_id'];
				}	
				
				// Next we'l grab only the assigned categories
			
				$sql = "SELECT DISTINCT(exp_categories.cat_id), parent_id FROM exp_categories
						LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id
						WHERE group_id ='$group_id'
						AND exp_category_posts.cat_id IS NOT NULL ";
				
				if ($parent_only === TRUE)
				{
					$sql .= " AND parent_id = 0";
				}
				
				$sql .= " ORDER BY parent_id, cat_order";
				
				$query = $DB->query($sql);
				if ($query->num_rows == 0)
					return false;
					
				// All the magic happens here, baby!!
				
				foreach($query->result as $row)
				{
					if ($row['parent_id'] != 0)
					{
						$this->find_parent($row['parent_id'], $all);
					}	
					
					$this->cat_full_array[] = $row['cat_id'];
				}
			
				$this->cat_full_array = array_unique($this->cat_full_array);
					
				$sql = "SELECT cat_id, parent_id, cat_name, cat_image, cat_description FROM exp_categories WHERE cat_id IN (";
		
				foreach ($this->cat_full_array as $val)
				{
					$sql .= $val.',';
				}
			
				$sql = substr($sql, 0, -1).')';
				
				$sql .= " ORDER BY parent_id, cat_order";
				
				$query = $DB->query($sql);
					  
				if ($query->num_rows == 0)
					return false;        
			}
			else
			{		
				$sql = "SELECT exp_categories.cat_name, exp_categories.cat_image, exp_categories.cat_description, exp_categories.cat_id, exp_categories.parent_id FROM exp_categories WHERE group_id ='$group_id' ";
						
				if ($parent_only === TRUE)
				{
					$sql .= " AND parent_id = 0";
				}
				
				$sql .= " ORDER BY parent_id, cat_order";
							
				$query = $DB->query($sql);
								  
				if ($query->num_rows == 0)
				{
						return '';
				}
			}  
			foreach($query->result as $row)
			{ 
				$this->temp_array[$row['cat_id']]  = array($row['cat_id'], $row['parent_id'], '1', $row['cat_name'], $row['cat_description'], $row['cat_image']);
			}
															
			foreach($this->temp_array as $key => $val) 
			{				
				if (0 == $val['1'])
				{    
					$this->cat_array[] = $val;
					$this->process_subcategories($key);
				}
			}
			
			unset($this->temp_array);
							
			foreach ($this->cat_array as $key => $val)
			{
				$chunk = str_replace(LD.'category_name'.RD, $val['3'], $TMPL->tagdata);
				$chunk = str_replace(LD.'category_description'.RD, $val['4'], $chunk);
				$chunk = str_replace(LD.'category_image'.RD, $val['5'], $chunk);
				$chunk = str_replace(LD.'category_id'.RD, $val['0'], $chunk);
				
				if ($val['4']== '')
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "", $chunk);	
				}
				else
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "\\1", $chunk);	
				}
				
				foreach($path as $k => $v)
				{	
					if ($this->use_category_names == TRUE)
					{
						$chunk = str_replace($k, $FNS->remove_double_slashes($v.'/'.$this->reserved_cat_segment.'/'.$val['3'].'/'), $chunk); 
					}
					else
					{
						$chunk = str_replace($k, $FNS->remove_double_slashes($v.'/C'.$val['0'].'/'), $chunk); 
					}
				}	
				
				$str .= $chunk;
			}
		    
			if ($TMPL->fetch_param('backspace'))
			{            
				$str =& rtrim(str_replace("&#47;", "/", $str));
				$str =& substr($str, 0, - $TMPL->fetch_param('backspace'));
				$str =& str_replace("/", "&#47;", $str);
			}
		}

        return $str;
    }
    // END

    
    //--------------------------------
    // Process Subcategories
    //--------------------------------
        
    function process_subcategories($parent_id)
    {        
    		foreach($this->temp_array as $key => $val) 
        {
            if ($parent_id == $val['1'])
            {
				$this->cat_array[] = $val;
				$this->process_subcategories($key);
			}
        }
    }
    // END


    // ----------------------------------------
    //  Category archives
    // ----------------------------------------

    function category_archive()
    {
        global $TMPL, $LOC, $FNS, $REGX, $DB, $LANG;		
		
		if (USER_BLOG !== FALSE)
		{
		    $group_id = UB_CAT_GRP;
		    
		    $weblog_id = UB_BLOG_ID;
		}
		else
		{
            $sql = "SELECT cat_group, weblog_id FROM exp_weblogs";
		
            if ($weblog = $TMPL->fetch_param('weblog'))
            {
                $sql .= " WHERE blog_name = '".$DB->escape_str($weblog)."'";
		    }
		    
		    $query = $DB->query($sql);
		        
            if ($query->num_rows != 1)
            {
                return '';
            }
            
            $group_id = $query->row['cat_group'];
            
            $weblog_id = $query->row['weblog_id'];
		}
		
		        
		$sql = "SELECT exp_category_posts.cat_id, exp_weblog_titles.entry_id, exp_weblog_titles.title, exp_weblog_titles.url_title
		        FROM exp_weblog_titles, exp_category_posts
		        WHERE weblog_id = '$weblog_id'
		        AND exp_weblog_titles.entry_id = exp_category_posts.entry_id ";
		        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
        
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
			$sql .= "AND exp_weblog_titles.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= "AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$timestamp.") ";
        }
        		        
		$sql .= "AND exp_weblog_titles.status != 'closed' ";
        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
		
            $sql .= $FNS->sql_andor_string($status, 'exp_weblog_titles.status');
        }
        else
        {
            $sql .= "AND exp_weblog_titles.status = 'open' ";
        }
			
		$orderby  =& $TMPL->fetch_param('orderby');
					
		switch ($orderby)
		{
			case 'date'					: $sql .= "ORDER BY exp_weblog_titles.entry_date";
				break;
			case 'expiration_date'		: $sql .= "ORDER BY exp_weblog_titles.expiration_date";
				break;
			case 'title'				: $sql .= "ORDER BY exp_weblog_titles.title";
				break;
			case 'comment_total'		: $sql .= "ORDER BY exp_weblog_titles.entry_date";
				break;
			case 'most_recent_comment'	: $sql .= "ORDER BY exp_weblog_titles.recent_comment_date desc, exp_weblog_titles.entry_date";
				break;
			default						: $sql .= "ORDER BY exp_weblog_titles.title";
				break;
		}
		
		$sort =& $TMPL->fetch_param('sort');
		
		switch ($sort)
		{
			case 'asc'	: $sql .= " asc";
				break;
			case 'desc'	: $sql .= " desc";
				break;
			default		: $sql .= " asc";
				break;
		}			
		        				
		$result = $DB->query($sql);
		$blog_array = array();
		
		$parent_only = ($TMPL->fetch_param('parent_only') == 'yes') ? TRUE : FALSE;

        $cat_chunk  = (preg_match("/".LD."categories\s*".RD."(.*?)".LD.SLASH."categories\s*".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : '';        
		
		$c_path = array();
		
		if (preg_match_all("#".LD."path(=.+?)".RD."#", $cat_chunk, $matches)) 
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{			
				if ( ! isset($path[$matches['0'][$i]]))
				{
					$c_path[$matches['0'][$i]] = $FNS->create_url($FNS->extract_path($matches['1'][$i]));
				}
			}
		}		
        
        $tit_chunk = (preg_match("/".LD."entry_titles\s*".RD."(.*?)".LD.SLASH."entry_titles\s*".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : '';        

		$t_path = array();
		
		if (preg_match_all("#".LD."path(=.+?)".RD."#", $tit_chunk, $matches)) 
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{			
				if ( ! isset($path[$matches['0'][$i]]))
				{
					$t_path[$matches['0'][$i]] = $FNS->create_url($FNS->extract_path($matches['1'][$i]));
				}
			}
		}		

		$str = '';
				
		if ($TMPL->fetch_param('style') == '' OR $TMPL->fetch_param('style') == 'nested')
        {
			if ($result->num_rows > 0 && $tit_chunk != '')
			{        		
        			$i = 0;	
				foreach($result->result as $row)
				{
					$chunk = "<li>".str_replace(LD.'category_name'.RD, '', $tit_chunk)."</li>";
					
					foreach($t_path as $tkey => $tval)
					{
						$chunk = str_replace($tkey, $FNS->remove_double_slashes($tval.'/'.$row['url_title'].'/'), $chunk); 
					}
			
					$blog_array[$i.'_'.$row['cat_id']] = str_replace(LD.'title'.RD, $row['title'], $chunk);
					$i++;
				}
			}
			
			$this->category_tree(
									array(
											'group_id'		=> $group_id, 
											'path'			=> $c_path,
											'template'		=> $cat_chunk,
											'blog_array' 	=> $blog_array,
											'parent_only'	=> $parent_only,
											'show_empty'		=> $TMPL->fetch_param('show_empty')
										  )
								);
						
			if (count($this->category_list) > 0)
			{			
				foreach ($this->category_list as $val)
				{
					$str .= $val; 
				}
			}
		}
		else
		{		
			$sql = "SELECT exp_categories.cat_name, exp_categories.cat_id, exp_categories.cat_description, exp_categories.cat_image, exp_categories.parent_id 
					FROM exp_categories ";
					
			if ($TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= " LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id ";
			}
			
			$sql .= " WHERE group_id ='$group_id' ";
			
			if ($TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= "AND exp_category_posts.cat_id IS NOT NULL";
			}
					
			if ($parent_only == TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY parent_id, cat_order";
			
		 	$query = $DB->query($sql);               
               
            if ($query->num_rows > 0)
            {
            		$used = array();
            
                foreach($query->result as $row)
                { 
					if ( ! isset($used[$row['cat_name']]))
					{
						$chunk = str_replace(LD.'category_id'.RD, $row['cat_id'], $cat_chunk);
						$chunk = str_replace(LD.'category_name'.RD, $row['cat_name'], $chunk);
						$chunk = str_replace(LD.'category_image'.RD, $row['cat_image'], $chunk);
						$chunk = str_replace(LD.'category_description'.RD, $row['cat_description'], $chunk);
						
						if ($row['cat_description'] == '')
						{
							$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "", $chunk);	
						}
						else
						{
							$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "\\1", $chunk);	
						}
												
						foreach($c_path as $ckey => $cval)
						{
							$chunk = str_replace($ckey, $FNS->remove_double_slashes($cval.'/C'.$row['cat_id'].'/'), $chunk); 
						}
					
						$str .= $chunk;
						$used[$row['cat_name']] = TRUE;
					}
										
					foreach($result->result as $trow)
					{
						if ($trow['cat_id'] == $row['cat_id'])
						{						
							$chunk = str_replace(LD.'title'.RD, $trow['title'], $tit_chunk);
							$chunk = str_replace(LD.'category_name'.RD, $row['cat_name'], $chunk);
					
							foreach($t_path as $tkey => $tval)
							{
								$chunk = str_replace($tkey, $FNS->remove_double_slashes($tval.'/'.$trow['url_title'].'/'), $chunk); 
							}
							
							$str .= $chunk;
						}
					}
                }
		    }
		    
			if ($TMPL->fetch_param('backspace'))
			{            
				$str =& rtrim(str_replace("&#47;", "/", $str));
				$str =& substr($str, 0, - $TMPL->fetch_param('backspace'));
				$str =& str_replace("/", "&#47;", $str);
			}
		}
				
        return $str;
    }
    // END


    //--------------------------------
    // Locate category parent
    //--------------------------------
    // This little recursive gem will travel up the
    // category tree until it finds the category ID
    // number of any parents.  It's used by the function 
    // below

	function find_parent($parent, $all)
	{	
		foreach ($all as $cat_id => $parent_id)
		{
			if ($parent == $cat_id)
			{
				$this->cat_full_array[] = $cat_id;
				
				if ($parent_id != 0)
					$this->find_parent($parent_id, $all);				
			}
		}
	}
	// END


    //--------------------------------
    // Category Tree
    //--------------------------------

    // This function and the next create a nested, hierarchical category tree

    function category_tree($cdata = array())
    {  
        global $FNS, $REGX, $DB, $TMPL, $FNS;
        
        $default = array('group_id', 'path', 'template', 'depth', 'blog_array', 'parent_only', 'show_empty');
        
        foreach ($default as $val)
        {
        		$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
        }
        
        if ($group_id == '')
        {
            return false;
        }
        
		//--------------------------------
		//  Are we showing empty categories
		//--------------------------------
		
		// If we are only showing categories that have been assigned to entries
		// we need to run a couple queries and run a recursive function that
		// figures out whether any given category has a parent.
		// If we don't do this we will run into a problem in which parent categories
		// that are not assigned to a blog will be supressed, and therefore, any of its
		// children will be supressed also - even if they are assigned to entries.
		// So... we wil first fetch all the category IDs, then only the ones that are assigned
		// to entries, and lastly we'll recursively run up the tree and fetch all parents.
		// Follow that?  No?  Me neither... 
           
		if ($show_empty == 'no')
		{	
			// First we'll grab all category ID numbers
		
			$query = $DB->query("SELECT cat_id, parent_id FROM exp_categories WHERE group_id ='$group_id' ORDER BY parent_id, cat_order");
			
			$all = array();
			
			// No categories exist?  Let's go home..
			if ($query->num_rows == 0)
				return false;
			
			foreach($query->result as $row)
			{
				$all[$row['cat_id']] = $row['parent_id'];
			}	
			
			// Next we'l grab only the assigned categories
		
			$sql = "SELECT DISTINCT(exp_categories.cat_id), parent_id FROM exp_categories
					LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id
					WHERE group_id ='$group_id'
					AND exp_category_posts.cat_id IS NOT NULL ";
			
			if ($parent_only === TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY parent_id, cat_order";
			
			$query = $DB->query($sql);
			if ($query->num_rows == 0)
				return false;
				
			// All the magic happens here, baby!!
			
			foreach($query->result as $row)
			{
				if ($row['parent_id'] != 0)
				{
					$this->find_parent($row['parent_id'], $all);
				}	
				
				$this->cat_full_array[] = $row['cat_id'];
			}
        
        		$this->cat_full_array = array_unique($this->cat_full_array);
        		
			$sql = "SELECT cat_id, parent_id, cat_name, cat_image, cat_description FROM exp_categories WHERE cat_id IN (";
        
        		foreach ($this->cat_full_array as $val)
        		{
        			$sql .= $val.',';
        		}
        
			$sql = substr($sql, 0, -1).')';
			
			$sql .= " ORDER BY parent_id, cat_order";
			
			$query = $DB->query($sql);
				  
			if ($query->num_rows == 0)
				return false;        
        }
		else
		{
        
			$sql = "SELECT DISTINCT(exp_categories.cat_id), exp_categories.parent_id, exp_categories.cat_name, exp_categories.cat_image, exp_categories.cat_description
					FROM exp_categories
					WHERE group_id ='$group_id' ";
					
			if ($parent_only === TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY parent_id, cat_order";
			
			$query = $DB->query($sql);
				  
			if ($query->num_rows == 0)
				return false;
		}		
		
		foreach($query->result as $row)
		{
			$this->cat_array[$row['cat_id']]  = array($row['parent_id'], $row['cat_name'], $row['cat_image'], $row['cat_description']);
		}
 
    		$this->temp_array = $this->cat_array;
    	
    		$open = 0;
    	
        foreach($this->cat_array as $key => $val) 
        { 
            if (0 == $val['0'])
            {
				if ($open == 0)
				{
					$open = 1;
					
					$this->category_list[] = "<ul>\n";
				}
				
				$chunk = str_replace(LD.'category_id'.RD, $key, $template);
				$chunk = str_replace(LD.'category_name'.RD, $val['1'], $chunk);
				$chunk = str_replace(LD.'category_image'.RD, $val['2'], $chunk);
				$chunk = str_replace(LD.'category_description'.RD, $val['3'], $chunk);
				
				if ($val['3'] == '')
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "", $chunk);	
				}
				else
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "\\1", $chunk);	
				}
            					
				foreach($path as $pkey => $pval)
				{
					if ($this->use_category_names == TRUE)
					{
						$chunk = str_replace($pkey, $FNS->remove_double_slashes($pval.'/'.$this->reserved_cat_segment.'/'.$val['1'].'/'), $chunk); 
					}
					else
					{
						$chunk = str_replace($pkey, $FNS->remove_double_slashes($pval.'/C'.$key.'/'), $chunk); 
					}
				}	
            	   
				$this->category_list[] = "\t<li>".$chunk;            	
				
				if (is_array($blog_array))
				{
					$fillable_entries = 'n';
					
					foreach($blog_array as $k => $v)
					{
						$k = substr($k, strpos($k, '_') + 1);
					
						if ($key == $k)
						{
							if ($fillable_entries == 'n')
							{
								$this->category_list[] = "\n\t\t<ul>\n";
								$fillable_entries = 'y';
							}
														
							$this->category_list[] = "\t\t\t$v";
						}
					}
				}
				
				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$this->category_list[] = "\t\t</ul>\n";
				}
								
				$this->category_subtree(
											array(
													'parent_id'		=> $key, 
													'path'			=> $path, 
													'template'		=> $template,
													'blog_array' 	=> $blog_array
												  )
									);
				$t = '';
				
				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$t .= "\t";
				}
				
				$this->category_list[] = $t."</li>\n";
				
				unset($this->temp_array[$key]);
				
				$this->close_ul(0);
            }
        }        
    }
    // END  
    
    
    
    //--------------------------------
    // Category Sub-tree
    //--------------------------------
        
    function category_subtree($cdata = array())
    {
        global $TMPL, $FNS;
        
        $default = array('parent_id', 'path', 'template', 'depth', 'blog_array', 'show_empty');
        
        foreach ($default as $val)
        {
        		$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
        }
        
        $open = 0;
        
        if ($depth == '') 
        		$depth = 1;
                
		$tab = '';
		for ($i = 0; $i <= $depth; $i++)
			$tab .= "\t";
        
		foreach($this->cat_array as $key => $val) 
        {
            if ($parent_id == $val['0'])
            {
            		if ($open == 0)
				{
					$open = 1;            		
					$this->category_list[] = "\n".$tab."<ul>\n";
				}
				
				$chunk = str_replace(LD.'category_id'.RD, $key, $template);
				$chunk = str_replace(LD.'category_name'.RD, $val['1'], $chunk);
				$chunk = str_replace(LD.'category_image'.RD, $val['2'], $chunk);
				$chunk = str_replace(LD.'category_description'.RD, $val['3'], $chunk);
				
				if ($val['3'] == '')
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "", $chunk);	
				}
				else
				{
					$chunk = preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "\\1", $chunk);	
				}
		
				foreach($path as $pkey => $pval)
				{
					if ($this->use_category_names == TRUE)
					{
						$chunk = str_replace($pkey, $FNS->remove_double_slashes($pval.'/'.$this->reserved_cat_segment.'/'.$val['1'].'/'), $chunk); 
					}
					else
					{
						$chunk = str_replace($pkey, $FNS->remove_double_slashes($pval.'/C'.$key.'/'), $chunk); 
					}
				}	
				
				$this->category_list[] = $tab."\t<li>".$chunk;
				
				if (is_array($blog_array))
				{
					$fillable_entries = 'n';
					
					foreach($blog_array as $k => $v)
					{
						$k = substr($k, strpos($k, '_') + 1);
					
						if ($key == $k)
						{
							if ( ! isset($fillable_entries) || $fillable_entries == 'n')
							{
								$this->category_list[] = "\n{$tab}\t\t<ul>\n";
								$fillable_entries = 'y';
							}
							
							$this->category_list[] = "{$tab}\t\t\t$v";            			
						}
					}
				}
				 
				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$this->category_list[] = "{$tab}\t\t</ul>\n";
				}
				 
				$t = '';
												
				if ($this->category_subtree(
											array(
													'parent_id'		=> $key, 
													'path'			=> $path, 
													'template'		=> $template,
													'depth' 			=> $depth + 2,
													'blog_array' 	=> $blog_array
												  )
									) != 0 );
			
			if (isset($fillable_entries) && $fillable_entries == 'y')
			{
				$t .= "$tab\t";
			}        
							
				$this->category_list[] = $t."</li>\n";
				
				unset($this->temp_array[$key]);
				
				$this->close_ul($parent_id, $depth + 1);
            }
        } 
        return $open; 
    }
    // END



    //--------------------------------
    // Close </ul> tags
    //--------------------------------

	// This is a helper function to the above
	
    function close_ul($parent_id, $depth = 0)
    {	
		$count = 0;
		
		$tab = "";
		for ($i = 0; $i < $depth; $i++)
		{
			$tab .= "\t";
		}
    	
        foreach ($this->temp_array as $val)
        {
         	if ($parent_id == $val['0']) 
         	
         	$count++;
        }
            
        if ($count == 0) 
        	$this->category_list[] = $tab."</ul>\n";
    }
	// END




    // ----------------------------------------
    //  Weblog "category_heading" tag
    // ----------------------------------------

    function category_heading()
    {
        global $IN, $TMPL, $FNS, $DB;

		if ($IN->QSTR == '')
		{
		    return;
        }
        
        $qstring = $IN->QSTR;
        
		// --------------------------------------
		//  Remove page number 
		// --------------------------------------
		
		if (preg_match("#/P\d+#", $qstring, $match))
		{
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
		
		// --------------------------------------
		//  Remove "N" 
		// --------------------------------------

		if (preg_match("#/N(\d+)#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
		
		// Is the category being specified by name?
								
		if (preg_match("#^".$this->reserved_cat_segment."/#", $qstring, $match) AND $TMPL->fetch_param('weblog'))
		{		
			$qstring = str_replace($this->reserved_cat_segment.'/', '', $qstring);
				
			$sql = "SELECT cat_group FROM exp_weblogs WHERE ";
			
			$sql .= (USER_BLOG !== FALSE) ? " weblog_id='".UB_BLOG_ID."'" : " blog_name='".$TMPL->fetch_param('weblog')."'";
				
			$query = $DB->query($sql);
			
			if ($query->num_rows == 1)
			{						
				$result = $DB->query("SELECT cat_id FROM exp_categories WHERE cat_name='$qstring' AND group_id='{$query->row['cat_group']}'");
			
				if ($result->num_rows == 1)
				{
					$qstring = 'C'.$result->row['cat_id'];
				}
			}
		}

		// Is the category being specified by ID?
		
		if ( ! preg_match("#^C(\d+)#", $qstring, $match))
		{					
			return '';
		}
				
		$query = $DB->query("SELECT cat_name, cat_description, cat_image FROM exp_categories WHERE cat_id = '".$DB->escape_str($match['1'])."'");
		
		if ($query->num_rows == 0)
		{
			return '';
		}
		
		if ($query->row['cat_description'] == '')
		{
			$TMPL->tagdata =& preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "", $TMPL->tagdata);	
		}
		else
		{
			$TMPL->tagdata =& preg_replace("/".LD."if category_description\s*".RD."(.*?)".LD.SLASH."if".RD."/s", "\\1", $TMPL->tagdata);	
		}

		$TMPL->tagdata =& preg_replace("/".LD."category_id\s*".RD."/s", $match['1'], $TMPL->tagdata);	
		$TMPL->tagdata =& preg_replace("/".LD."category_image\s*".RD."/s", $query->row['cat_image'], $TMPL->tagdata);	
		$TMPL->tagdata =& preg_replace("/".LD."category_name\s*".RD."/s",  $query->row['cat_name'],  $TMPL->tagdata);	
		$TMPL->tagdata =& preg_replace("/".LD."category_description\s*".RD."/s", $query->row['cat_description'], $TMPL->tagdata);	

		return $TMPL->tagdata;
    }
    // END
    
    


    // ----------------------------------------
    //  Weblog "next entry" link
    // ----------------------------------------

    function next_entry()
    {
        global $IN, $TMPL, $LOC, $DB, $FNS;
        
		if ($IN->QSTR == '')
		{
		    return;
        }
        
        $qstring = $IN->QSTR;
        
		// --------------------------------------
		//  Remove page number 
		// --------------------------------------
		
		if (preg_match("#/P\d+#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
		
		// --------------------------------------
		//  Remove "N" 
		// --------------------------------------

		if (preg_match("#/N(\d+)#", $qstring, $match))
		{	
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
                        
        $sql = "SELECT t1.entry_id, t1.title, t1.url_title 
				FROM exp_weblog_titles t1, exp_weblog_titles t2, exp_weblogs 
				WHERE t1.weblog_id = exp_weblogs.weblog_id ";
        		
        if (is_numeric($qstring))
        {
			$sql .= " AND t1.entry_id != '".$DB->escape_str($qstring)."' AND t2.entry_id  = '".$DB->escape_str($qstring)."' ";
        }
        else
        {
			$sql .= " AND t1.url_title != '".$DB->escape_str($qstring)."' AND t2.url_title  = '".$DB->escape_str($qstring)."' ";
        }
        
        if ($weblog = $TMPL->fetch_param('weblog'))
        {
        	$sql .= " AND exp_weblogs.blog_name = '{$weblog}' ";
        }

		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {			
        		$sql .= " AND t1.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= " AND t1.entry_date > t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
        }
        		
        if (USER_BLOG === FALSE)
        {
			$sql .= " AND exp_weblogs.is_user_blog = 'n' ";
        
            if ($blog_name = $TMPL->fetch_param('weblog'))
            {
                $sql .= $FNS->sql_andor_string($blog_name, 'blog_name', 'exp_weblogs');
            }
        }
        else
        {
        		$sql .= " AND weblog_id = '".UB_BLOG_ID."' ";
        }
        
		$sql .= " AND t1.status != 'closed' ";
        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
        
            $sql .= $FNS->sql_andor_string($status, 't1.status');
        }
        else
        {
            $sql .= "AND t1.status = 'open' ";
        }
        		
        $sql .= " ORDER BY t1.entry_date LIMIT 1";
                                
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
        		return;
        }
        
		$path = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		
		$path .= '/'.$query->row['url_title'].'/';
		
		$TMPL->tagdata =& preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata =& preg_replace("#".LD."title".RD."#", preg_quote($query->row['title']), $TMPL->tagdata);	

        return $FNS->remove_double_slashes(stripslashes($TMPL->tagdata));
    }
    // END




    // ----------------------------------------
    //  Weblog "previous entry" link
    // ----------------------------------------

    function prev_entry()
    {
        global $IN, $TMPL, $LOC, $FNS, $DB;
        
		if ($IN->QSTR == '')
		{
		    return;
        }
        
        $qstring = $IN->QSTR;
        
		// --------------------------------------
		//  Remove page number 
		// --------------------------------------
		
		if (preg_match("#/P\d+#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
		
		// --------------------------------------
		//  Remove "N" 
		// --------------------------------------

		if (preg_match("#/N(\d+)#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
        
        $sql = "SELECT t1.entry_id, t1.title, t1.url_title
				FROM exp_weblog_titles t1, exp_weblog_titles t2, exp_weblogs 
				WHERE t1.weblog_id = exp_weblogs.weblog_id ";
        		
        if (is_numeric($qstring))
        {
			$sql .= " AND t1.entry_id != '".$DB->escape_str($qstring)."' AND t2.entry_id  = '".$DB->escape_str($qstring)."' ";
        }
        else
        {
			$sql .= " AND t1.url_title != '".$DB->escape_str($qstring)."' AND t2.url_title  = '".$DB->escape_str($qstring)."' ";
        }
        
        if ($weblog = $TMPL->fetch_param('weblog'))
        {
        	$sql .= " AND exp_weblogs.blog_name = '{$weblog}' ";
        }
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
        		$sql .= " AND t1.entry_date < ".$timestamp." ";
        }

        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= " AND t1.entry_date < t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
        }
        		
        if (USER_BLOG === FALSE)
        {
        		$sql .= " AND exp_weblogs.is_user_blog = 'n' ";
        
            if ($blog_name = $TMPL->fetch_param('weblog'))
            {
                $sql .= $FNS->sql_andor_string($blog_name, 'blog_name', 'exp_weblogs');
            }
        }
        else
        {
        		$sql .= " AND weblog_id = '".UB_BLOG_ID."' ";
        }
        
		$sql .= " AND t1.status != 'closed' ";
        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
        
            $sql .= $FNS->sql_andor_string($status, 't1.status');
        }
        else
        {
            $sql .= "AND t1.status = 'open' ";
        }
        		
        $sql .= " ORDER BY t1.entry_date desc LIMIT 1";
                        
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
        		return;
        }
        
		$path = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		
		$path .= '/'.$query->row['url_title'].'/';
		
		$TMPL->tagdata =& preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata =& preg_replace("#".LD."title".RD."#", preg_quote($query->row['title']), $TMPL->tagdata);	

        return $FNS->remove_double_slashes(stripslashes($TMPL->tagdata));
    }
    // END



    // ----------------------------------------
    //  Weblog "month links"
    // ----------------------------------------

    function month_links()
    {
        global $TMPL, $LOC, $FNS, $REGX, $DB, $LANG, $SESS;
        
        $return = '';
        
        // ----------------------------------------
        //  Build query
        // ----------------------------------------
        
        // Fetch the timezone array and calculate the offset so we can localize the month/year
        $zones = $LOC->zones();
        
        $offset = ( ! isset($zones[$SESS->userdata['timezone']]) || $zones[$SESS->userdata['timezone']] == '') ? 0 : ($zones[$SESS->userdata['timezone']]*60*60);        
        		
		if (substr($offset, 0, 1) == '-')
		{
			$calc = 'entry_date - '.substr($offset, 1);
		}
		elseif (substr($offset, 0, 1) == '+')
		{
			$calc = 'entry_date + '.substr($offset, 1);
		}
		else
		{
			$calc = 'entry_date + '.$offset;
		}
                
        $sql = "SELECT DISTINCT year(FROM_UNIXTIME(".$calc.")) AS year, 
        				MONTH(FROM_UNIXTIME(".$calc.")) AS month 
        				FROM exp_weblog_titles 
        				WHERE entry_id != '' ";
                
                
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
        
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
			$sql .= " AND exp_weblog_titles.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= " AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$timestamp.") ";
        }
        
        // ----------------------------------------------
        // Limit to/exclude specific weblogs
        // ----------------------------------------------
    
        if (USER_BLOG !== FALSE)
        {
            $sql .= "AND weblog_id = '".UB_BLOG_ID."' ";
        }
        else
        {
       
            if ($weblog = $TMPL->fetch_param('weblog'))
            {
                $wsql = "SELECT weblog_id FROM exp_weblogs WHERE is_user_blog = 'n' ";
            
                $wsql .= $FNS->sql_andor_string($weblog, 'blog_name');
                                
                $query = $DB->query($wsql);
                
                if ($query->num_rows > 0)
                {
                    $sql .= " AND ";
                
                    if ($query->num_rows == 1)
                    {
                        $sql .= "weblog_id = '".$query->row['weblog_id']."' ";
                    }
                    else
                    {
                        $sql .= "(";
                        
                        foreach ($query->result as $row)
                        {
                            $sql .= "weblog_id = '".$row['weblog_id']."' OR ";
                        }
                        
                        $sql = substr($sql, 0, - 3);
                        
                        $sql .= ") ";
                    }
                }
            }
        }
           
        $sql .= " ORDER BY entry_date desc ";   
                
        if ($TMPL->fetch_param('limit'))
        {
            $sql .= " LIMIT ".$TMPL->fetch_param('limit');  
        }
                
        $query = $DB->query($sql);

        if ($query->num_rows == 0)
        {
            return '';
        }
        
        foreach ($query->result as $row)
        { 
            $tagdata =& $TMPL->tagdata;
							
			$month = (strlen($row['month']) == 1) ? '0'.$row['month'] : $row['month'];
			$year  = $row['year'];
				
            $month_name = $LOC->localize_month($month);  
            
            // ----------------------------------------
            //  parse path
            // ----------------------------------------
            
            foreach ($TMPL->var_single as $key => $val)
            {              
                if (ereg("^path", $key))
                {                    
                    $tagdata =& $TMPL->swap_var_single(
                                                        $val, 
                                                        $FNS->create_url($FNS->extract_path($key).'/'.$year.'/'.$month), 
                                                        $tagdata
                                                      );
                }

                // ----------------------------------------
                //  parse month (long)
                // ----------------------------------------
                
                if ($key == 'month')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $LANG->line($month_name['1']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (short)
                // ----------------------------------------
                
                if ($key == 'month_short')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $LANG->line($month_name['0']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (numeric)
                // ----------------------------------------
                
                if ($key == 'month_num')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $month, $tagdata);
                }
                
                // ----------------------------------------
                //  parse year
                // ----------------------------------------
                
                if ($key == 'year')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $year, $tagdata);
                }
                
                // ----------------------------------------
                //  parse year (short)
                // ----------------------------------------
                
                if ($key == 'year_short')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, substr($year, 2), $tagdata);
                }
             }
             
             $return .= trim($tagdata)."\n";
         }
             
        return $return;    
    }
    // END



    // ----------------------------------------
    //  Related Entries
    // ----------------------------------------

	function related_entries()
	{
		global $DB, $IN, $TMPL, $LOC, $FNS;
				
		if ($IN->QSTR == '')
		{
			return false;
		}
		
        $qstring = $IN->QSTR;
        
		// --------------------------------------
		//  Remove page number
		// --------------------------------------
		
		if (preg_match("#/P\d+#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}
		
		// --------------------------------------
		//  Remove "N" 
		// --------------------------------------

		if (preg_match("#/N(\d+)#", $qstring, $match))
		{			
			$qstring = $FNS->remove_double_slashes(str_replace($match['0'], '', $qstring));
		}

		// ----------------------------------
		// Find Categories for Entry
		// ----------------------------------
		
		$sql = "SELECT exp_categories.cat_id, exp_categories.cat_name
				FROM exp_weblog_titles
				INNER JOIN exp_category_posts ON exp_weblog_titles.entry_id = exp_category_posts.entry_id
				INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id 
				WHERE exp_categories.cat_id IS NOT NULL ";
	
		$sql .= ( ! is_numeric($qstring)) ? "AND exp_weblog_titles.url_title = '{$qstring}' " : "AND exp_weblog_titles.entry_id = '{$qstring}' ";
				
		$query = $DB->query($sql);
		
		if ($query->num_rows == 0)
		{
			return false;
		}
		
		// ----------------------------------
		// Build category array
		// ----------------------------------
		
		$cat_array = array();
		
		// We allow the option of adding or subtracting cat_id's
		$categories = ( ! $TMPL->fetch_param('category'))  ? '' : $TMPL->fetch_param('category');
		
		if (ereg("^not ", $categories))
		{
			$categories = substr($categories, 4);
			$not_categories = explode('|',$categories);
		}
		else
		{
			$add_categories = explode('|',$categories);
		}
		
		foreach($query->result as $row)
		{
			if ( ! isset($not_categories) || array_search($row['cat_id'], $not_categories) === false)
			{ 
				$cat_array[] = $row['cat_id'];
			}
		}
		
		// User wants some categories added, so we add these cat_id's
		
		if (isset($add_categories) && sizeof($add_categories) > 0)
		{
			foreach($add_categories as $cat_id)
			{
				$cat_array[] = $cat_id;	
			}
		}
		
		// Just in case
		$cat_array = array_unique($cat_array);
		
		if (sizeof($cat_array) == 0)
		{
			return false;
		}
		
		// ----------------------------------
		// Build category string
		// ----------------------------------
		
		$cats = '';
		
		foreach($cat_array as $cat_id)
		{
			if ($cat_id != '')
			{
				$cats .= $cat_id.'|';
			}
		}
		$cats = substr($cats, 0, -1);
				
		// ----------------------------------
		// Manually set paramters
		// ----------------------------------
		
		$TMPL->tagparams['category']		= $cats;		
		$TMPL->tagparams['dynamic']			= 'off';
		$TMPL->tagparams['rdf']				= 'off';
		$TMPL->tagparams['not_entry_id']	= $qstring; // Exclude the current entry
		
		// Set user submitted paramters
		
		$params = array('weblog', 'username', 'status', 'orderby', 'sort');
		
		foreach ($params as $val)
		{
			if ($TMPL->fetch_param($val) != FALSE)
			{
				$TMPL->tagparams[$val] = $TMPL->fetch_param($val);
			}
		}
		
		if ($TMPL->fetch_param('limit') == FALSE)
		{
			$TMPL->tagparams['limit'] = 10;
		}
		
		// ----------------------------------
		// Run the weblog parser
		// ----------------------------------
		
        $this->initialize();
		$this->entry_id 	= '';
		$qstring 			= '';        

        $this->build_sql_query();
        
        $this->query = $DB->query($this->sql);
        
        if ($this->query->num_rows == 0)
        {
            return false;
        }
        
        if ( ! class_exists('Typography'))
        {
            require PATH_CORE.'core.typography'.EXT;
        }
                
        $this->TYPE = new Typography;   
                    
        $this->parse_weblog_entries();
        		
		$backspace = ( ! $TMPL->fetch_param('backspace'))  ? '0' : $TMPL->fetch_param('backspace');
		
		if ($backspace != 0)
		{
			if (is_numeric($backspace))
			{
				$this->return_data =& rtrim(str_replace("&#47;", "/", $this->return_data));
				$this->return_data =& substr($this->return_data, 0, - $backspace);
				$this->return_data =& str_replace("/", "&#47;", $this->return_data);
			}
		}
		
		return $this->return_data;
	}
	// END
        
    
    
    // ----------------------------------------
    //  Weblog Calendar
    // ----------------------------------------
    
    function calendar()
    {
    		global $LANG, $TMPL, $LOC, $IN, $DB, $FNS, $PREFS, $SESS;

		// ----------------------------------------
		//  Determine the Month and Year
		// ----------------------------------------
		
		$year  = '';
		$month = '';
		
		// Hard-coded month/year via tag parameters
		
		if ($TMPL->fetch_param('month') AND $TMPL->fetch_param('year'))
		{
			$year 	= $TMPL->fetch_param('year');
			$month	= $TMPL->fetch_param('month');
			
			if (strlen($month) == 1)
			{
				$month = '0'.$month;
			}
		}
		else
		{
			// Month/year in query string
		
			if (preg_match("#(\d{4}/\d{2})#", $IN->QSTR, $match))
			{
				$ex = explode('/', $match['1']);
				
				$time = mktime(0, 0, 0, $ex['1'], 01, $ex['0']);
				// $time = $LOC->set_localized_time(mktime(0, 0, 0, $ex['1'], 01, $ex['0']));

				$year  = date("Y", $time);
				$month = date("m", $time);
			}
			else
			{
				// Defaults to current month/year
			
				$year  = date("Y", $LOC->set_localized_time($LOC->now));
				$month = date("m", $LOC->set_localized_time($LOC->now));
			}
    	}
    	    	
    	    
		// ----------------------------------------
		//  Set Unix timestamp for the given month/year
		// ----------------------------------------
    	
		$local_date = mktime(12, 0, 0, $month, 1, $year);
		// $local_date = $LOC->set_localized_time($local_date);

		// ----------------------------------------
		//  Determine the total days in the month
		// ----------------------------------------

        $adjusted_date = $LOC->adjust_date($month, $year);
        
        $month	= $adjusted_date['month'];
        $year	= $adjusted_date['year'];  
        
		$total_days = $LOC->fetch_days_in_month($month, $year); 
		
		$previous_date 	= mktime(12, 0, 0, $month-1, 1, $year);
		$next_date 		= mktime(12, 0, 0, $month+1, 1, $year);
    	
		// ----------------------------------------
		//	Set the starting day of the week
		// ----------------------------------------
				
		// This can be set using a parameter in the tag:  start_day="saturday"
		// By default the calendar starts on sunday
		
		$start_days = array('sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6);
				
		$start_day = (isset($start_days[$TMPL->fetch_param('start_day')])) ? $start_days[$TMPL->fetch_param('start_day')]: 0;
    	
		$date = getdate($local_date);
		$day  = $start_day + 1 - $date["wday"];
		
		while ($day > 1)
		{
    			$day -= 7;
		}
		
		// ----------------------------------------
		//  {previous_path="weblog/index"}
		// ----------------------------------------
		
		// This variables points to the previous month

		if (preg_match_all("#".LD."previous_path=(.+?)".RD."#", $TMPL->tagdata, $matches))
		{
			$adjusted_date =& $LOC->adjust_date($month - 1, $year, TRUE);
					
			foreach ($matches['1'] as $match)
			{
				$path = $FNS->create_url($match).$adjusted_date['year'].'/'.$adjusted_date['month'].'/';
				
				$TMPL->tagdata =& preg_replace("#".LD."previous_path=.+?".RD."#", $path, $TMPL->tagdata, 1);
			}
		}
		
		// ----------------------------------------
		//  {next_path="weblog/index"}
		// ----------------------------------------
		
		// This variables points to the next month

		if (preg_match_all("#".LD."next_path=(.+?)".RD."#", $TMPL->tagdata, $matches))
		{
			$adjusted_date =& $LOC->adjust_date($month + 1, $year, TRUE);
					
			foreach ($matches['1'] as $match)
			{
				$path = $FNS->create_url($match).$adjusted_date['year'].'/'.$adjusted_date['month'].'/';
				
				$TMPL->tagdata =& preg_replace("#".LD."next_path=.+?".RD."#", $path, $TMPL->tagdata, 1);
			}
		}
		
		// ----------------------------------------
		//  {date format="%m %Y"}
		// ----------------------------------------
		
		// This variable is used in the heading of the calendar
		// to show the month and year

		if (preg_match_all("#".LD."date format=[\"|'](.+?)[\"|']".RD."#", $TMPL->tagdata, $matches))
		{		
			foreach ($matches['1'] as $match)
			{
				$TMPL->tagdata =& preg_replace("#".LD."date format=.+?".RD."#", $LOC->decode_date($match, $local_date), $TMPL->tagdata, 1);
			}
		}
		
		// ----------------------------------------
		//  {previous_date format="%m %Y"}
		// ----------------------------------------
		
		// This variable is used in the heading of the calendar
		// to show the month and year

		if (preg_match_all("#".LD."previous_date format=[\"|'](.+?)[\"|']".RD."#", $TMPL->tagdata, $matches))
		{		
			foreach ($matches['1'] as $match)
			{
				$TMPL->tagdata =& preg_replace("#".LD."previous_date format=.+?".RD."#", $LOC->decode_date($match, $previous_date), $TMPL->tagdata, 1);
			}
		}
		
		// ----------------------------------------
		//  {next_date format="%m %Y"}
		// ----------------------------------------
		
		// This variable is used in the heading of the calendar
		// to show the month and year

		if (preg_match_all("#".LD."next_date format=[\"|'](.+?)[\"|']".RD."#", $TMPL->tagdata, $matches))
		{		
			foreach ($matches['1'] as $match)
			{
				$TMPL->tagdata =& preg_replace("#".LD."next_date format=.+?".RD."#", $LOC->decode_date($match, $next_date), $TMPL->tagdata, 1);
			}
		}

				
		// ----------------------------------------
		//  Day Heading
		// ----------------------------------------
		/*
			This code parses out the headings for each day of the week
			Contained in the tag will be this variable pair:
			
			{calendar_heading}
			<td class="calendarDayHeading">{lang:weekday_abrev}</td>
			{/calendar_heading}
		
			There are three display options for the header:
			
			{lang:weekday_abrev} = S M T W T F S
			{lang:weekday_short} = Sun Mon Tues, etc.
			{lang:weekday_long} = Sunday Monday Tuesday, etc.
		
		*/
		
		foreach (array('Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa') as $val)
		{
			$day_names_a[] = ( ! $LANG->line($val)) ? $val : $LANG->line($val);
		}
		
		foreach (array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat') as $val)
		{
			$day_names_s[] = ( ! $LANG->line($val)) ? $val : $LANG->line($val);
		}
		
		foreach (array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') as $val)
		{
			$day_names_l[] = ( ! $LANG->line($val)) ? $val : $LANG->line($val);
		}
						
		if (preg_match("/".LD."calendar_heading".RD."(.+?)".LD.SLASH."calendar_heading".RD."/s", $TMPL->tagdata, $match))
		{	
			$temp = '';
		
			for ($i = 0; $i < 7; $i ++)
			{
				$chunk =& trim($match['1'])."\n";
				
				$chunk =& str_replace(LD.'lang:weekday_abrev'.RD, $day_names_a[($start_day + $i) %7], $chunk);
				$chunk =& str_replace(LD.'lang:weekday_short'.RD, $day_names_s[($start_day + $i) %7], $chunk);
				$chunk =& str_replace(LD.'lang:weekday_long'.RD,  $day_names_l[($start_day + $i) %7], $chunk);
			
				$temp .= $chunk;
			}

			$TMPL->tagdata = preg_replace ("/".LD."calendar_heading".RD.".+?".LD.SLASH."calendar_heading".RD."/s", trim($temp), $TMPL->tagdata);
		}
	
	
		// ----------------------------------------
		//  Separate out cell data
		// ----------------------------------------
		
		// We need to strip out the various variable pairs
		// that allow us to render each calendar cell.
		// We'll do this up-front and assign temporary markers
		// in the template which we will replace with the final
		// data later
		
		$row_start 			= ''; 
		$row_end 			= ''; 

		$row_chunk 			= ''; 
		$row_chunk_m		= '94838dkAJDei8azDKDKe01';
		
		$entries 			= ''; 
		$entries_m			= 'Gm983TGxkedSPoe0912NNk';

		$if_today 			= ''; 
		$if_today_m			= 'JJg8e383dkaadPo20qxEid';
		
		$if_entries 		= ''; 
		$if_entries_m		= 'Rgh43K0L0Dff9003cmqQw1';

		$if_not_entries 	= ''; 
		$if_not_entries_m	= 'yr83889910BvndkGei8ti3';

		$if_blank 			= ''; 
		$if_blank_m			= '43HDueie4q7pa8dAAseit6';

		
		if (preg_match("/".LD."calendar_rows".RD."(.+?)".LD.SLASH."calendar_rows".RD."/s", $TMPL->tagdata, $match))
		{	
			$row_chunk = trim($match['1']);
			
			//  Fetch all the entry_date variable
												
			if (preg_match_all("/".LD."entry_date\s+format=[\"'](.*?)[\"']".RD."/s", $row_chunk, $matches))
			{
				for ($j = 0; $j < count($matches['0']); $j++)
				{
					$matches['0'][$j] = str_replace(LD, '', $matches['0'][$j]);
					$matches['0'][$j] = str_replace(RD, '', $matches['0'][$j]);

					$entry_dates[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
				}
			}			
									
			if (preg_match("/".LD."row_start".RD."(.+?)".LD.SLASH."row_start".RD."/s", $row_chunk, $match))
			{
				$row_start = trim($match['1']);
			
				$row_chunk = trim(str_replace ($match['0'], "", $row_chunk));
			}
			
			if (preg_match("/".LD."row_end".RD."(.+?)".LD.SLASH."row_end".RD."/s", $row_chunk, $match))
			{
				$row_end = trim($match['1']);
			
				$row_chunk = trim(str_replace($match['0'], "", $row_chunk));
			}
						
            foreach ($TMPL->var_cond as $key => $val)
            {
				if ($val['3'] == 'today')
				{
					$if_today = trim($val['2']);
					
					$row_chunk = str_replace ($val['1'], $if_today_m, $row_chunk);
					
					unset($TMPL->var_cond[$key]);
				}
            
				if ($val['3'] == 'entries')
				{
					$if_entries = trim($val['2']);
					
					$row_chunk = str_replace ($val['1'], $if_entries_m, $row_chunk);
					
					unset($TMPL->var_cond[$key]);
				}
		
				if ($val['3'] == 'not_entries')
				{
					$if_not_entries = trim($val['2']);
					
					$row_chunk = str_replace ($val['1'], $if_not_entries_m, $row_chunk);
					
					unset($TMPL->var_cond[$key]);
				}
				
				if ($val['3'] == 'blank')
				{
					$if_blank = trim($val['2']);
					
					$row_chunk = str_replace ($val['1'], $if_blank_m, $row_chunk);
					
					unset($TMPL->var_cond[$key]);
				}
				
				if (preg_match("/".LD."entries".RD."(.+?)".LD.SLASH."entries".RD."/s", $if_entries, $match))
				{
					$entries = trim($match['1']);
				
					$if_entries = trim(str_replace($match['0'], $entries_m, $if_entries));
				}
				
			}		
				
			$TMPL->tagdata = preg_replace ("/".LD."calendar_rows".RD.".+?".LD.SLASH."calendar_rows".RD."/s", $row_chunk_m, $TMPL->tagdata);
		}
		
        // ----------------------------------------
        //  Fetch {switch} variable
        // ----------------------------------------
        
        // This variable lets us use a different CSS class
        // for the current day
				
		$switch_t = '';
		$switch_c = '';
					
		if ($TMPL->fetch_param('switch'))
		{
			$x = explode("|", $TMPL->fetch_param('switch'));
			
			if (count($x) == 2)
			{
				$switch_t = $x['0'];
				$switch_c = $x['1'];
			}
		}
		
        // ----------------------------------------
        //  Build the SQL query
        // ----------------------------------------
				
        $this->initialize();
                
        $this->tagparams['rdf']	= 'off';
        
		$this->build_sql_query('/'.$year.'/'.$month.'/');
		
		if ($this->sql != '')
		{
			$query = $DB->query($this->sql);
				
			// We need to assign the variables contained 
			// in the previously extracted chunk of the template 
					
			$TMPL->assign_variables($row_chunk);
	
			$data = array();
			
			if ($query->num_rows > 0)
			{  			
				// We'll need this later
			
				if ( ! class_exists('Typography'))
				{
					require PATH_CORE.'core.typography'.EXT;
				}
						
				$TYPE = new Typography;   
			 
				// ----------------------------------------
				//  Fetch query results and build data array
				// ----------------------------------------
	
				foreach ($query->result as $row)
				{
				
					// ----------------------------------------
					//  Define empty arrays and strings
					// ----------------------------------------
					
					$defaults = array(
										'entry_date'			=> 'a',
										'permalink'			=> 'a',
										'title_permalink'	=> 'a',
										'author'				=> 's',
										'profile_path'		=> 'a',
										'id_path'			=> 'a',
										'base_fields' 		=> 'a',
										'comment_tb_total'	=> 's',
										'day_path'			=> 's'
										);
										
										
					foreach ($defaults as $key => $val)
					{
						$$key = ($val == 'a') ? array() : '';
					}				
	
					// ---------------------------
					//  Single Variables
					// ---------------------------
	
					foreach ($TMPL->var_single as $key => $val)
					{ 
						
						if (isset($entry_dates[$key]))
						{
							foreach ($entry_dates[$key] as $dvar)
								$val =& str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], TRUE), $val);					
		
							$entry_date[$key] = $val;		
						}
						
						
						// ----------------------------------------
						//  parse permalink
						// ----------------------------------------
						
						if (ereg("^permalink", $key))
						{                     
							if ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX')
							{
								$path = $FNS->extract_path($key).'/'.$row['entry_id'];
							}
							else
							{
								$path = $row['entry_id'];
							}
						
							$permalink[$key] = $FNS->create_url($path, 1);
						}
						
						// ----------------------------------------
						//  parse title permalink
						// ----------------------------------------
						
						if (ereg("^title_permalink", $key) || ereg("^url_title_path", $key))
						{ 
							if ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX')
							{
								$path = $FNS->extract_path($key).'/'.$row['url_title'];
							}
							else
							{
								$path = $row['url_title'];
							}
								
							$title_permalink[$key] = $FNS->create_url($path, 1);
								
						}
							
						// ----------------------------------------
						//  {author}
						// ----------------------------------------
						
						if ($key == "author")
						{
							$author = ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'];
						}
						
						// ----------------------------------------
						//  profile path
						// ----------------------------------------
						
						if (ereg("^profile_path", $key))
						{                       
							$profile_path[$key] = $FNS->create_url($FNS->extract_path($key).'/'.$row['member_id']);
						}
				
						// ----------------------------------------
						//  parse comment_path or trackback_path
						// ----------------------------------------
						
						if (ereg("^comment_path", $key) || ereg("^trackback_path", $key) || ereg("^entry_id_path", $key) )
						{                       
							$id_path[$key] =& $FNS->create_url($FNS->extract_path($key).'/'.$row['entry_id']);
						}
						
						// ----------------------------------------
						//  parse {comment_tb_total}
						// ----------------------------------------
						
						if ($key == "comment_tb_total")
						{        
							$comment_tb_total = $row['comment_total'] + $row['trackback_total'];					
						}
						
						// ----------------------------------------
						//  Basic fields (username, screen_name, etc.)
						// ----------------------------------------
						 
						if (isset($row[$val]))
						{                    
							$base_fields[$key] = $row[$val];
						}
						
						// ----------------------------------------
						//  {day_path}
						// ----------------------------------------
						
						if (ereg("^day_path", $key))
						{               
							$d = date('d', $LOC->set_localized_time($row['entry_date']));
							$m = date('m', $LOC->set_localized_time($row['entry_date']));
							$y = date('Y', $LOC->set_localized_time($row['entry_date']));
											
							if ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX')
							{
								$path = $FNS->extract_path($key).'/'.$y.'/'.$m.'/'.$d;
							}
							else
							{
								$path = $y.'/'.$m.'/'.$d;
							}
							
							$if_entries = str_replace(LD.$key.RD, LD.'day_path'.RD, $if_entries);
						
							$day_path = $FNS->create_url($path, 1);
						}
						
					}
					// END FOREACH SINGLE VARIABLES
					
				
					// ----------------------------------------
					//  Build Data Array
					// ----------------------------------------
					
					$d = date('d', $LOC->set_localized_time($row['entry_date']));
					
					if (substr($d, 0, 1) == '0')
					{
						$d = substr($d, 1);
					}
					
					$data[$d][] = array(
											$TYPE->parse_type($row['title'], array('text_format' => 'lite', 'html_format' => 'none', 'auto_links' => 'n', 'allow_img_url' => 'no')),
											$row['url_title'],
											$entry_date,
											$permalink,
											$title_permalink,
											$author,
											$profile_path,
											$id_path,
											$base_fields,
											$comment_tb_total,
											$day_path
										);
				
				} // END FOREACH 
			} // END if ($query->num_rows > 0)
		} // END if ($this->query != '')
		
        // ----------------------------------------
        //  Build Calendar Cells
        // ----------------------------------------
        
        $out = '';  
        
		$today = getdate($LOC->set_localized_time($LOC->now));

		while ($day <= $total_days)
		{    	
			$out .= $row_start;       
			
			for ($i = 0; $i < 7; $i++)
			{    	        
				if ($day > 0 AND $day <= $total_days)
				{ 					
					if ($if_entries != '' AND isset($data[$day]))
					{					
						$out .= str_replace($if_entries_m, $this->var_replace($if_entries, $data[$day], $entries), $row_chunk);
						
						$out =& str_replace(LD.'day_path'.RD, $data[$day]['0']['10'], $out);
					}
					else
					{
						$out .= str_replace($if_not_entries_m, $if_not_entries, $row_chunk);
					}
					
					$out =& str_replace(LD.'day_number'.RD, $day, $out);
					
					
					if ($day == $today["mday"] AND $month == $today["mon"] AND $year == $today["year"])
					{
						$out =& str_replace(LD.'switch'.RD, $switch_t, $out);
					}
					else
					{
						$out =& str_replace(LD.'switch'.RD, $switch_c, $out);
					}
				}
				else
				{
					$out .= str_replace($if_blank_m, $if_blank, $row_chunk);
				}
				      	        
        	    		$day++;
			}
    	    
			$out .= $row_end;   
		}
    	
		// Garbage collection
		$out =& str_replace($entries_m,			'', $out);
		$out =& str_replace($if_blank_m,			'', $out);
		$out =& str_replace($if_today_m,			'', $out);
		$out =& str_replace($if_entries_m,		'', $out);
		$out =& str_replace($if_entries_m,		'', $out);
		$out =& str_replace($if_not_entries_m,	'', $out);

    	
		return str_replace ($row_chunk_m, $out, $TMPL->tagdata);
    }
    // END
    

	// ----------------------------------------
	//  Replace Calendar Variables
	// ----------------------------------------

    function var_replace($chunk, $data, $row = '')
    { 
		if ($row != '')
		{
			$temp = '';

			foreach ($data as $val)
			{
				$str = $row;
				
				$str =& str_replace(LD.'title'.RD,				$val['0'], 	$str);
				$str =& str_replace(LD.'url_title'.RD,			$val['1'], 	$str);
				$str =& str_replace(LD.'author'.RD,				$val['5'], 	$str);
				$str =& str_replace(LD.'comment_tb_total'.RD,		$val['9'], 	$str);
				$str =& str_replace(LD.'day_path'.RD,				$val['10'], $str);
				

				// Entry Date
				foreach ($val['2'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
				
				// Permalink
				foreach ($val['3'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
				
				// Title permalink
				foreach ($val['4'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
				
				// Profile path
				foreach ($val['6'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
				
				// ID path
				foreach ($val['7'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
				
				// Base Fields
				foreach ($val['8'] as $k => $v)
				{
					$str =& str_replace(LD.$k.RD, $v, $str);
				}
			
				$temp .= $str;
			}
			
			$chunk =& str_replace('Gm983TGxkedSPoe0912NNk', $temp, $chunk);
		}
					
		return $chunk;
    }
    // END
  
  
  
    // ----------------------------------------
    //  Trackback RDF
    // ----------------------------------------

    function trackback_rdf($TB)
    {
        
return "<!--
<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\"
         xmlns:trackback=\"http://madskills.com/public/xml/rss/module/trackback/\"
         xmlns:dc=\"http://purl.org/dc/elements/1.1/\">
<rdf:Description
    rdf:about=\"".$TB['about']."\"
    trackback:ping=\"".$TB['ping']."\"
    dc:title=\"".$TB['title']."\"
    dc:identifier=\"".$TB['identifier']."\" 
    dc:subject=\"".$TB['subject']."\"
    dc:description=\"".$TB['description']."\"
    dc:creator=\"".$TB['creator']."\"
    dc:date=\"".$TB['date']."\" />
</rdf:RDF>
-->";
    }
    // END



    // ----------------------------------------
    //  Insert a new weblog entry
    // ----------------------------------------
    
    // This function serves dual purpose:
    // 1. It allows submitted data to be previewed
    // 2. It allows submitted data to be inserted

	function insert_new_entry()
	{
        global $IN, $FNS, $OUT, $LANG, $SESS, $LOC;

		$LANG->fetch_language_file('weblog');
		$LANG->fetch_language_file('publish');
		
		// Ya gotta be logged-in billy bob...

		if ($SESS->userdata['member_id'] == 0) 
      	{ 
            return $OUT->show_user_error('general', $LANG->line('weblog_must_be_logged_in'));        
      	}

		// ----------------------------------------
		//  Prep data for insertion
		// ----------------------------------------

		if ( ! $IN->GBL('preview', 'POST'))
		{
			unset($_POST['hidden_pings']);
			unset($_POST['status_id']);
			unset($_POST['allow_cmts']);
			unset($_POST['allow_tbks']);
			unset($_POST['sticky_entry']);
		
			if ( ! $IN->GBL('entry_date', 'POST'))
			{
				$_POST['entry_date'] = $LOC->set_human_time($LOC->now);
			}
			
			if ( ! class_exists('Display'))
			{
				require PATH_CP.'cp.display'.EXT;
			}
			
			global $DSP;
			
			$DSP = new Display();
			
			if ( ! class_exists('Publish'))
			{
				require PATH_CP.'cp.publish'.EXT;
			}
					
			$PB = new Publish();
			
			return $PB->submit_new_entry(FALSE);
		
		} // END Insert
		
		// ----------------------------------------
		//  Preview Entry
		// ----------------------------------------    
       
        if ($IN->GBL('PRV', 'POST') == '')
        {
			$LANG->fetch_language_file('weblog');
        
            return $OUT->show_user_error('general', $LANG->line('weblog_no_preview_template'));        
        }
      
		$FNS->clear_caching('all', $_POST['PRV']);
        
        require PATH_CORE.'core.template'.EXT;
        
        global $TMPL;
        
        $TMPL = new Template();
        
		$preview = ( ! $IN->GBL('PRV', 'POST')) ? '' : $IN->GBL('PRV', 'POST');

        if ( ! ereg("/", $preview))
        		return FALSE;

		$ex = explode("/", $preview);

		if (count($ex) != 2)
        		return FALSE;

        $TMPL->run_template_engine($ex['0'], $ex['1']);
	}
	// END


    // ----------------------------------------
    //  Stand-alone version of the entry form
    // ----------------------------------------
    
    function entry_form($return_form = FALSE, $captcha = '')
    {
        global $TMPL, $LANG, $LOC, $OUT, $DB, $IN, $REGX, $FNS, $SESS, $PREFS;
        
        $field_data	= '';
        $catlist		= '';
        $status		= '';
      
		$LANG->fetch_language_file('weblog');
		
		// No loggy? No looky...
		
		if ($SESS->userdata['member_id'] == 0) 
      	{
            return '';    
      	}
      	
		if ( ! $weblog = $TMPL->fetch_param('weblog'))
		{
			return $OUT->show_user_error('general', $LANG->line('weblog_not_specified'));        
      	}
      	
      	// Fetch the action ID number.  Even though we don't need it until later
      	// we'll grab it here.  If not found it means the action table doesn't
      	// contain the ID, which means the user has not updated properly.  Ya know?
      	
      	if ( ! $insert_action = $FNS->fetch_action_id('Weblog', 'insert_new_entry'))
      	{
			return $OUT->show_user_error('general', $LANG->line('weblog_no_action_found'));        
      	}
      	
        // We need to first determine which weblog to post the entry into.

        $assigned_weblogs = $FNS->fetch_assigned_weblogs();
        
        $weblog_id = ( ! $IN->GBL('weblog_id', 'POST')) ? '' : $IN->GBL('weblog_id', 'POST');

		if ($weblog_id == '')
		{			
			if ($SESS->userdata['weblog_id'] != 0)
			{
				$weblog_id = $SESS->userdata['weblog_id'];
			}
			elseif (sizeof($assigned_weblogs) == 1)
			{
				$weblog_id = $assigned_weblogs['0'];
			}
			else
			{
				$query = $DB->query("SELECT weblog_id from exp_weblogs WHERE blog_name = '".$DB->escape_str($weblog)."' AND is_user_blog = 'n'");
	
				if ($query->num_rows == 1)
				{
					$weblog_id = $query->row['weblog_id'];
				}
				else
				{
					return $TMPL->no_results();
				}
			}
		}
		
        
        // ----------------------------------------------
        //  Security check
        // ---------------------------------------------
                
        if ( ! in_array($weblog_id, $assigned_weblogs))
        { 
        	return $TMPL->no_results();
        }

        // ----------------------------------------------
        //  Fetch weblog preferences
        // ---------------------------------------------
                
        $query = $DB->query("SELECT * FROM  exp_weblogs WHERE weblog_id = '$weblog_id'");     
                
        if ($query->num_rows == 0)
        {
            return "The weblog you have specified does not exist.";
        }

        foreach ($query->row as $key => $val)
        {
            $$key = $val;
        }

        // ----------------------------------------
        //   Return the "no cache" version of the form
        // ----------------------------------------

        if ($return_form == FALSE)
        {
    		$nc = '{{NOCACHE_WEBLOG_FORM ';
    		
    		if (count($TMPL->tagparams) > 0)
    		{
    			foreach ($TMPL->tagparams as $key => $val)
    			{
    				$nc .= ' '.$key.'="'.$val.'" ';
    			}
    		}
    		
    		$nc .= '}}'.$TMPL->tagdata.'{{/NOCACHE_FORM}}';
    		
    		return $nc;
        }
                
                
        // ----------------------------------------------
        //  JavaScript For URL Title
        // ---------------------------------------------
        
        $convert_ascii = ($PREFS->ini('auto_convert_high_ascii') == 'y') ? TRUE : FALSE;        
        $word_separator = $PREFS->ini('word_separator') != "dash" ? '_' : '-';
        
        $url_title_js = <<<EOT
        <script language="javascript" type="text/javascript"> 
        <!--
        function liveUrlTitle()
        {
			var NewText = document.getElementById("title").value;
			var separator = "{$word_separator}";
			NewText = NewText.toLowerCase();
			
			if (separator != "_")
			{
				NewText = NewText.replace(/\_/g, separator);
			}
			else
			{
				NewText = NewText.replace(/\-/g, separator);
			}
	
			NewText = NewText.replace(/<(.*?)>/g, '');
			NewText = NewText.replace(/\&#\d+\;/g, '');
			NewText = NewText.replace(/\&\#\d+?\;/g, '');
			NewText = NewText.replace(/\&\S+?\;/g,'');
			NewText = NewText.replace(/['\"\?\.\!*\$\#@%;:,=\(\)\[\]]/g,'');
			NewText = NewText.replace(/\s+/g, separator);
			NewText = NewText.replace(/\//g, separator);
			NewText = NewText.replace(/[^a-z0-9-_]/g,'');
			NewText = NewText.replace(/\+/g, separator);
			NewText = NewText.replace(/\&/g,'');
			NewText = NewText.replace(/-$/g,'');
			NewText = NewText.replace(/_$/g,'');
			NewText = NewText.replace(/^_/g,'');
			NewText = NewText.replace(/^-/g,'');
						
			if (document.getElementById("url_title"))
			{
				document.getElementById("url_title").value = NewText;			
			}
			else
			{
				document.forms['entryform'].elements['url_title'].value = NewText; 
			}

		}
		
		-->
		</script>
EOT;



		$LANG->fetch_language_file('publish');

        // ----------------------------------------
        //  Compile form declaration and hidden fields
        // ----------------------------------------
        
        $RET = (isset($_POST['RET'])) ? $_POST['RET'] : $FNS->fetch_current_uri();
        $XID = ( ! isset($_POST['XID'])) ? '' : $_POST['XID'];        
        $PRV = (isset($_POST['PRV'])) ? $_POST['PRV'] : '{PREVIEW_TEMPLATE}';

        $hidden_fields = array(
                                'ACT'      				=> $insert_action,
                                'RET'      				=> $RET,
                                'PRV'      				=> $PRV,
                                'URI'      				=> ($IN->URI == '') ? 'index' : $IN->URI,
                                'XID'      				=> $XID,
                                'return_url'			=> (isset($_POST['return_url'])) ? $_POST['return_url'] : $TMPL->fetch_param('return'),
                                'author_id'				=> $SESS->userdata['member_id'],
                                'weblog_id'				=> $weblog_id
                              );
                              
        // ----------------------------------------
        //  Add status to hidden fields
        // ----------------------------------------
                               
		$status_id = ( ! isset($_POST['status_id'])) ? $TMPL->fetch_param('status') : $_POST['status_id'];
		
		if ($status_id == 'Open' || $status_id == 'Closed')
			$status_id = strtolower($status_id);

		$status_query = $DB->query("SELECT * FROM exp_statuses WHERE group_id = '$status_group' order by status_order");

		if ($status_id != '')
		{	
			$closed_flag = TRUE;
		
			if ($status_query->num_rows > 0)
			{  			
				foreach ($status_query->result as $row)
				{
					if ($row['status'] == $status_id)
						$closed_flag = FALSE;
				}
			}
		
			$hidden_fields['status'] = ($closed_flag == TRUE) ? 'closed' : $status_id;
		}
		
	
        // ----------------------------------------
        //  Add "allow" options
        // ----------------------------------------
                                       
		$allow_cmts = ( ! isset($_POST['allow_cmts'])) ? $TMPL->fetch_param('allow_comments') : $_POST['allow_cmts'];

		if ($allow_cmts != '' AND $comment_system_enabled == 'y')
		{		
			$hidden_fields['allow_comments'] = ($allow_cmts == 'yes') ? 'y' : 'n';
		}
		
		$allow_tbks = ( ! isset($_POST['allow_tbks'])) ? $TMPL->fetch_param('allow_trackbacks') : $_POST['allow_tbks'];

		if ($allow_tbks != '')
		{
			$hidden_fields['allow_trackbacks'] = ($allow_tbks == 'yes') ? 'y' : 'n';
		}
		
		$sticky_entry = ( ! isset($_POST['sticky_entry'])) ? $TMPL->fetch_param('sticky_entry') : $_POST['sticky_entry'];

		if ($sticky_entry != '')
		{
			$hidden_fields['sticky'] = ($sticky_entry == 'yes') ? 'y' : 'n';
		}
		
        // ----------------------------------------
        //  Add categories to hidden fields
        // ----------------------------------------

		if ($category_id = $TMPL->fetch_param('category'))
		{
			if (isset($_POST['category']))
			{
				foreach ($_POST as $key => $val)
				{                
					if (strstr($key, 'category') AND is_array($val))
					{
						$i =0;
						foreach ($val as $v)
						{
							$hidden_fields['category['.($i++).']'] = $val;
						}
					}            
				}
			}
			else
			{
				if ( ! ereg("\|", $category_id))
				{
					$hidden_fields['category[]'] = $category_id;
				}
				else
				{
					if (ereg("^\|", $category_id))
					{
						$category_id =& substr($category_id, 1);
					}
					if (ereg("\|$", $category_id))
					{
						$category_id =& substr($category_id, 0, -1);
					}
					
					$i = 0;
					foreach(explode("|", $category_id) as $val)
					{
						$hidden_fields['category['.($i++).']'] = $val;
					}
				}
			}
		}

        // ----------------------------------------
        //  Add pings to hidden fields
        // ----------------------------------------
		
		$hidden_pings = ( ! isset($_POST['hidden_pings'])) ? $TMPL->fetch_param('hidden_pings') : $_POST['hidden_pings'];
		
		if ($hidden_pings == 'yes')
		{
			$hidden_fields['hidden_pings'] = 'yes';
		
			$ping_servers = $this->fetch_ping_servers('new');
		
			if (is_array($ping_servers) AND count($ping_servers) > 0)
			{
				$i = 0;
				foreach ($ping_servers as $val)
				{
					if ($val['1'] != '')
						$hidden_fields['ping['.($i++).']'] = $val['0'];
				}
			}
		}

		// -------------------------------------
		//  Parse out the tag
		// -------------------------------------	

		$tagdata = $TMPL->tagdata;
		
		$which = ($IN->GBL('preview', 'POST')) ? 'preview' : 'new';
		
		
		
		//--------------------------------
		// Fetch Custom Fields
		//--------------------------------
		
		$query = $DB->query("SELECT * FROM  exp_weblog_fields WHERE group_id = '$field_group' ORDER BY field_order");
		
		$fields = array();
		
		if ($which == 'preview')
		{
			foreach ($query->result as $row)
			{
				$fields['field_id_'.$row['field_id']] = $row['field_name'];
			}
		}

		// ----------------------------------------
		//  Preview
		// ----------------------------------------		
		
		if (preg_match("#".LD."preview".RD."(.+?)".LD.'/'."preview".RD."#s", $tagdata, $match))
		{									
			if ($which != 'preview')
			{  
				$tagdata = str_replace ($match['0'], '', $tagdata);
			}
			else
			{   
				// ----------------------------------------
				//  Instantiate Typography class
				// ----------------------------------------        
			  
				if ( ! class_exists('Typography'))
				{
					require PATH_CORE.'core.typography'.EXT;
				}
				
				$TYPE = new Typography;
					
				$match['1'] = str_replace(LD.'title'.RD, stripslashes($IN->GBL('title', 'POST')), $match['1']);
				
				// We need to grab each global array index and do a little formatting
				
				$str = '';
				
				foreach($_POST as $key => $val)
				{            
					if ( ! is_array($val))
					{
						if (strstr($key, 'field_id'))
						{
							$expl = explode('field_id_', $key);
																					
							$txt_fmt = ( ! isset($_POST['field_ft_'.$expl['1']])) ? 'xhtml' : $_POST['field_ft_'.$expl['1']];
						
							$temp = $TYPE->parse_type( stripslashes($val), 
													 array(
																'text_format'   => $txt_fmt,
																'html_format'   => $weblog_html_formatting,
																'auto_links'    => $weblog_allow_img_urls,
																'allow_img_url' => $weblog_auto_link_urls
														   )
													);
							
							
							if (isset($fields[$key]))
							{
								$match['1'] = str_replace(LD.$fields[$key].RD, $temp, $match['1']);
							}
													
							$str .= $temp;
						} 
					}
				}

				$match['1'] = str_replace(LD.'display_custom_fields'.RD, $str, $match['1']);
				$tagdata = str_replace ($match['0'], $match['1'], $tagdata);
			}
		}
		
	
		// -------------------------------------
		//  Formatting buttons
		// -------------------------------------	

		if (preg_match("#".LD."formatting_buttons".RD."#s", $tagdata))
		{	
			if ( ! defined('BASE'))
			{		
				$s = ($PREFS->ini('admin_session_type') != 'c') ? $SESS->userdata['session_id'] : 0;
				
				define('BASE', $PREFS->ini('cp_url', TRUE).'?S='.$s);  
			}
			
		
			if ( ! class_exists('Display'))
			{
				require PATH_CP.'cp.display'.EXT;
			}
			
			global $DSP;
			$DSP = new Display;
			
			if ( ! class_exists('Publish'))
			{
				require PATH_CP.'cp.publish'.EXT;
			}
			
			$PUB = new Publish;
		
			$tagdata = str_replace(LD.'formatting_buttons'.RD, $PUB->html_formatting_buttons($SESS->userdata['member_id'], $field_group), $tagdata);
		}

		// -------------------------------------
		//  Fetch the {custom_fields} chunk
		// -------------------------------------	
		
		$custom_fields = '';

		if (preg_match("#".LD."custom_fields".RD."(.+?)".LD.'/'."custom_fields".RD."#s", $tagdata, $match))
		{
			$custom_fields = trim($match['1']);
		
			$tagdata = str_replace($match['0'], LD.'temp_custom_fields'.RD, $tagdata);
		}
				
		// If we have custom fields to show, generate them		
		
		if ($custom_fields != '')
		{
			$field_array = array('textarea', 'textinput', 'pulldown');
			
			$textarea 	= '';
			$textinput 	= '';
			$pulldown	= '';
			$pd_options	= '';
			$required	= '';
			
			foreach ($field_array as $val)
			{
				if (preg_match("#".LD."\s*if\s+".$val.RD."(.+?)".LD.'/'."if".RD."#s", $custom_fields, $match))
				{
					$$val = $match['1'];
					
					if ($val == 'pulldown')
					{
						if (preg_match("#".LD."options".RD."(.+?)".LD.'/'."options".RD."#s", $pulldown, $pmatch))
						{
							$pd_options = $pmatch['1'];
						
							$pulldown = str_replace ($pmatch['0'], LD.'temp_pd_options'.RD, $pulldown);
						}
					}
				
					$custom_fields = str_replace($match['0'], LD.'temp_'.$val.RD, $custom_fields);
				}
			}
			
			if (preg_match("#".LD."if\s+required".RD."(.+?)".LD.'/'."if".RD."#s", $custom_fields, $match))
			{			
				$required = $match['1'];
				
				$custom_fields = str_replace($match['0'], LD.'temp_required'.RD, $custom_fields);
			}			
			
			//--------------------------------
			// Parse Custom Fields
			//--------------------------------
						
			$build = '';
		
			foreach ($query->result as $row)
			{
				$temp_chunk = $custom_fields;
				$temp_field = '';
			
				switch ($which)
				{
					case 'preview' : 
							$field_data = ( ! isset( $_POST['field_id_'.$row['field_id']] )) ?  '' : $_POST['field_id_'.$row['field_id']];
							$field_fmt  = ( ! isset( $_POST['field_ft_'.$row['field_id']] )) ? $row['field_fmt'] : $_POST['field_ft_'.$row['field_id']];
						break;
					case 'edit'    :
							$field_data = ( ! isset( $result->row['field_id_'.$row['field_id']] )) ? '' : $result->row['field_id_'.$row['field_id']];
							$field_fmt  = ( ! isset( $result->row['field_ft_'.$row['field_id']] )) ? $row['field_fmt'] : $result->row['field_ft_'.$row['field_id']];
						break;
					default        :
							$field_data = '';
							$field_fmt  = $row['field_fmt'];
						break;
				}
				
										
				//--------------------------------
				// Textarea field types
				//--------------------------------
			
				if ($row['field_type'] == 'textarea' AND $textarea != '')
				{               									
					$temp_chunk = str_replace(LD.'temp_textarea'.RD, $textarea, $temp_chunk);
				}
				if ($row['field_type'] == 'text' AND $textinput != '')
				{               									
					$temp_chunk = str_replace(LD.'temp_textinput'.RD, $textinput, $temp_chunk);
				}
				elseif ($row['field_type'] == 'select' AND $pulldown != '')
				{   
					$pdo = '';
					$temp_options = '';
					
					foreach (explode("\n", trim($row['field_list_items'])) as $v)
					{  
						$temp_options = $pd_options;
					
						$v = trim($v);
													
						$temp_options = str_replace(LD.'option_name'.RD, $v, $temp_options);
						$temp_options = str_replace(LD.'option_value'.RD, $v, $temp_options);
						$temp_options = str_replace(LD.'selected'.RD, ($v == $field_data) ? 1 : '', $temp_options);
						
						$pdo .= $temp_options;
					}
						
					$pulldown = str_replace(LD.'temp_pd_options'.RD, $pdo, $pulldown);
					$temp_chunk = str_replace(LD.'temp_pulldown'.RD, $pulldown, $temp_chunk);
				} 
				
				if ($row['field_required'] == 'y') 
				{
					$temp_chunk = str_replace(LD.'temp_required'.RD, $required, $temp_chunk);
				}
				else
				{
					$temp_chunk = str_replace(LD.'temp_required'.RD, '', $temp_chunk);
				}
				
				$temp_chunk = str_replace(LD.'field_data'.RD, $field_data, $temp_chunk);
				$temp_chunk = str_replace(LD.'temp_textarea'.RD, '', $temp_chunk);
				$temp_chunk = str_replace(LD.'temp_textinput'.RD, '', $temp_chunk);
				$temp_chunk = str_replace(LD.'temp_pulldown'.RD, '', $temp_chunk);
				$temp_chunk = str_replace(LD.'temp_pd_options'.RD, '', $temp_chunk);
				
				$temp_chunk = str_replace(LD.'rows'.RD, ( ! isset($row['field_ta_rows'])) ? '10' : $row['field_ta_rows'], $temp_chunk);
				$temp_chunk = str_replace(LD.'field_label'.RD, $row['field_label'], $temp_chunk);
				$temp_chunk = str_replace(LD.'maxlengh'.RD, $row['field_maxl'], $temp_chunk);
				$temp_chunk = str_replace(LD.'field_name'.RD, 'field_id_'.$row['field_id'], $temp_chunk);
				$temp_chunk .= "\n<input type='hidden' name='field_ft_".$row['field_id']."' value='".$field_fmt."' />\n";
				
				$build .= $temp_chunk;
			}
			
			$tagdata = str_replace(LD.'temp_custom_fields'.RD, $build, $tagdata);
		}
		
		
		// ----------------------------------------
		//  Categories
		// ----------------------------------------		
		
		if (preg_match("#".LD."category_menu".RD."(.+?)".LD.'/'."category_menu".RD."#s", $tagdata, $match))
		{									
			$this->category_tree_form($cat_group, $which, $deft_category, $catlist);			
			
			if (count($this->categories) == 0)
			{  
				$tagdata = str_replace ($match['0'], '', $tagdata);
			}
			else
			{   
				$c = '';
				foreach ($this->categories as $val)
				{
					$c .= $val;
				}
								
				$match['1'] = str_replace(LD.'select_options'.RD, $c, $match['1']);
				$tagdata = str_replace ($match['0'], $match['1'], $tagdata);
			}
		}


		// ----------------------------------------
		//  Ping Servers
		// ----------------------------------------		
		
		if (preg_match("#".LD."ping_servers".RD."(.+?)".LD.'/'."ping_servers".RD."#s", $tagdata, $match))
		{	
			$field = (preg_match("#".LD."ping_row".RD."(.+?)".LD.'/'."ping_row".RD."#s", $tagdata, $match1)) ? $match1['1'] : '';
		
			if ( ! isset($match1['0']))
			{
				$tagdata = str_replace ($match['0'], '', $tagdata);
			}
		
       		$ping_servers = $this->fetch_ping_servers($which);
		
			if (is_array($ping_servers))
			{
				if (count($ping_servers) == 0)
				{  
					$tagdata = str_replace ($match['0'], '', $tagdata);
				}
				else
				{   
					$ping_build = '';
				
					foreach ($ping_servers as $val)
					{
						$temp = $field;
						
						$temp = str_replace(LD.'ping_value'.RD, $val['0'], $temp);						
						$temp = str_replace(LD.'ping_checked'.RD, $val['1'], $temp);
						$temp = str_replace(LD.'ping_server_name'.RD, $val['2'], $temp);
						
						$ping_build .= $temp;
					}
										
					$match['1'] = str_replace ($match1['0'], $ping_build, $match['1']);
					$tagdata = str_replace ($match['0'], $match['1'], $tagdata);
				}
			}
		}




		// ----------------------------------------
		//  Status
		// ----------------------------------------		
		
		if (preg_match("#".LD."status_menu".RD."(.+?)".LD.'/'."status_menu".RD."#s", $tagdata, $match))
		{
			if (isset($_POST['status']))
				$deft_status = $_POST['status'];
			
			if ($deft_status == '')
				$deft_status = 'open';
			
			if ($status == '') 
				$status = $deft_status;
							  
				//--------------------------------
				// Fetch disallowed statuses
				//--------------------------------
				
				$no_status_access = array();
		
				if ($SESS->userdata['group_id'] != 1)
				{
					$query = $DB->query("SELECT status_id FROM exp_status_no_access WHERE member_group = '".$SESS->userdata['group_id']."'");            
			
					if ($query->num_rows > 0)
					{
						foreach ($query->result as $row)
						{
							$no_status_access[] = $row['status_id'];
						}		
					}
				}
				
				//--------------------------------
				// Create status menu
				//--------------------------------
								
				$r = '';
				
				if ($status_query->num_rows == 0)
				{
					$selected = ($status == 'open') ? " selected='selected'" : '';
					$r .= "<option value='open'".$selected.">".$LANG->line('open')."</option>";
					$selected = ($status == 'closed') ? " selected='selected'" : '';
					$r .= "<option value='closed'".$selected.">".$LANG->line('closed')."</option>";
				}
				else
				{        		
					$no_status_flag = TRUE;
				
					foreach ($status_query->result as $row)
					{					
						$selected = ($status == $row['status']) ? " selected='selected'" : '';
						
						if ($selected != 1)
						{
							if (in_array($row['status_id'], $no_status_access))
							{
								continue;                
							}
						}
						
						$no_status_flag = FALSE;
						
						$status_name = ($row['status'] == 'open' OR $row['status'] == 'closed') ? $LANG->line($row['status']) : $row['status'];
																
						$r .= "<option value='".$REGX->form_prep($row['status'])."'".$selected.">". $REGX->form_prep($status_name)."</option>\n";					
					}
					
					if ($no_status_flag == TRUE)
					{
						$tagdata = str_replace ($match['0'], '', $tagdata);
					}
				}


				$match['1'] = str_replace(LD.'select_options'.RD, $r, $match['1']);
				$tagdata = str_replace ($match['0'], $match['1'], $tagdata);
		}

		
		// ----------------------------------------
		//  Trackback field
		// ----------------------------------------
		
		if (preg_match("#".LD."if\s+trackback".RD."(.+?)".LD.'/'."if".RD."#s", $tagdata, $match))
		{			
			if ($show_trackback_field == 'n')
			{
				$tagdata = str_replace ($match['0'], '', $tagdata);
			}
			else
			{			
				$tagdata = str_replace ($match['0'], $match['1'], $tagdata);
			}
		}
		
		// ----------------------------------------
		//  Parse single variables
		// ----------------------------------------
	
        foreach ($TMPL->var_single as $key => $val)
        {              
            // ----------------------------------------
            //  {title}
            // ----------------------------------------
            
            if ($key == 'title')
            {
                $title = ( ! isset($_POST['title'])) ? '' : $_POST['title'];

                $tagdata =& $TMPL->swap_var_single($key, $REGX->form_prep($title), $tagdata);
            }

            // ----------------------------------------
            //  {allow_comments}
            // ----------------------------------------
            
            if ($key == 'allow_comments')
            {
				if ($which == 'preview')
				{
					$checked = ( ! isset($_POST['allow_comments']) || $comment_system_enabled != 'y') ? '' : "checked='checked'";
				}
				else
				{
					$checked = ($deft_comments == 'n' || $comment_system_enabled != 'y') ? '' : "checked='checked'";
				}
				
                $tagdata =& $TMPL->swap_var_single($key, $checked, $tagdata);
            }

            // ----------------------------------------
            //  {allow_trackbacks}
            // ----------------------------------------
            
            if ($key == 'allow_trackbacks')
            {
				if ($which == 'preview')
				{
					$checked = ( ! isset($_POST['allow_trackbacks']) || $trackback_system_enabled != 'y') ? '' : "checked='checked'";
				}
				else
				{
					$checked = ($deft_trackbacks == 'n' || $trackback_system_enabled != 'y') ? '' : "checked='checked'";
				}
				
                $tagdata =& $TMPL->swap_var_single($key, $checked, $tagdata);
            }

            // ----------------------------------------
            //  {sticky}
            // ----------------------------------------
            
            if ($key == 'sticky')
            {
            		$checked = '';
            		
				if ($which == 'preview')
				{
					$checked = ( ! isset($_POST['sticky'])) ? '' : "checked='checked'";
				}
				
                $tagdata =& $TMPL->swap_var_single($key, $checked, $tagdata);
            }

            // ----------------------------------------
            //  {url_title}
            // ----------------------------------------

            if ($key == 'url_title')
            {
                $url_title = ( ! isset($_POST['url_title'])) ? '' : $_POST['url_title'];

                $tagdata =& $TMPL->swap_var_single($key, $url_title, $tagdata);
            }
            
            // ----------------------------------------
            //  {entry_date}
            // ----------------------------------------

            if ($key == 'entry_date')
            {
                $entry_date = ( ! isset($_POST['entry_date'])) ? $LOC->set_human_time($LOC->now) : $_POST['entry_date'];

                $tagdata =& $TMPL->swap_var_single($key, $entry_date, $tagdata);
            }
                        
            // ----------------------------------------
            //  {expiration_date}
            // ----------------------------------------

            if ($key == 'expiration_date')
            {
                $expiration_date = ( ! isset($_POST['expiration_date'])) ? '': $_POST['expiration_date'];
                
                $tagdata =& $TMPL->swap_var_single($key, $expiration_date, $tagdata);
            }

            // ----------------------------------------
            //  {comment_expiration_date}
            // ----------------------------------------

            if ($key == 'comment_expiration_date')
            {
             	$comment_expiration_date = '';
             
				if ($which == 'preview')
				{
                		$comment_expiration_date = ( ! isset($_POST['comment_expiration_date'])) ? '' : $_POST['comment_expiration_date'];
				}
				else
				{
					if ($comment_expiration > 0)
					{
						$comment_expiration_date = $comment_expiration * 86400;
						$comment_expiration_date = $comment_expiration_date + $LOC->now;
						$comment_expiration_date = $LOC->set_human_time($comment_expiration_date);
					}
            		}

                $tagdata =& $TMPL->swap_var_single($key, $comment_expiration_date, $tagdata);
            }
            
            
            // ----------------------------------------
            //  {trackback_urls}
            // ----------------------------------------

            if ($key == 'trackback_urls')
            {
                $trackback_urls = ( ! isset($_POST['trackback_urls'])) ? '' : $_POST['trackback_urls'];

                $tagdata =& $TMPL->swap_var_single($key, $trackback_urls, $tagdata);
            }
               
		}               
        
        // Build the form
                                         
        $res  = $FNS->form_declaration($hidden_fields, $RET, 'entryform');

		if ($TMPL->fetch_param('use_live_url') != 'no')
		{
 			$res .= $url_title_js;
		}
		
        $res .= stripslashes($tagdata);
        $res .= "</form>"; 
		
		return $res;
    }
    // END




    
    //-----------------------------
    // Category tree
    //-----------------------------
    // This function (and the next) create a higherarchy tree
    // of categories.

    function category_tree_form($group_id = '', $action = '', $default = '', $selected = '')
    {  
        global $IN, $REGX, $DB;
  
        // Fetch category group ID number
      
        if ($group_id == '')
        {        
            if ( ! $group_id = $IN->GBL('group_id'))
                return false;
        }
        
        // If we are using the category list on the "new entry" page
        // we need to gather the selected categories so we can highlight
        // them in the form.
        
        if ($action == 'preview')
        {
            $catarray = array();
        
            foreach ($_POST as $key => $val)
            {                
                if (strstr($key, 'category') AND is_array($val))
                {
                		foreach ($val as $k => $v)
                		{
                    		$catarray[$v] = $v;
                		}
                }            
            }
        }

        if ($action == 'edit')
        {
            $catarray = array();
            
            if (is_array($selected))
            {
                foreach ($selected as $key => $val)
                {
                    $catarray[$val] = $val;
                }
            }
        }
            
        // Fetch category groups
        
        $query = $DB->query("SELECT cat_name, cat_id, parent_id
                             FROM exp_categories 
                             WHERE group_id = '$group_id' 
                             ORDER BY parent_id, cat_order");
              
        if ($query->num_rows == 0)
        { 
            return false;
        }  
        
        // Assign the query result to a multi-dimensional array
                    
        foreach($query->result as $row)
        {        
            $cat_array[$row['cat_id']]  = array($row['parent_id'], $row['cat_name']);
        }
	
		$size = count($cat_array) + 1;
			        
        // Build our output...
        
        $sel = '';

        foreach($cat_array as $key => $val) 
        {
            if (0 == $val['0']) 
            {
                if ($action == 'new')
                {
                    $sel = ($default == $key) ? '1' : '';   
                }
                else
                {
                    $sel = (isset($catarray[$key])) ? '1' : '';   
                }
                
				$s = ($sel != '') ? " selected='selected'" : '';

				$this->categories[] = "<option value='".$key."'".$s.">".$val['1']."</option>\n";

                $this->category_subtree_form($key, $cat_array, $depth=1, $action, $default, $selected);
            }
        }
        
        $this->categories[] = '</select>';
    }
    // END  
    
    
    
    
    //-----------------------------------------------------------
    // Category sub-tree
    //-----------------------------------------------------------
    // This function works with the preceeding one to show a
    // hierarchical display of categories
    //-----------------------------------------------------------
        
    function category_subtree_form($cat_id, $cat_array, $depth, $action, $default = '', $selected = '')
    {
        global $DSP, $IN, $DB, $REGX, $LANG;

        $spcr = "&nbsp;";
        
        
        // Just as in the function above, we'll figure out which items are selected.
        
        if ($action == 'preview')
        {
            $catarray = array();
        
            foreach ($_POST as $key => $val)
            {
                if (strstr($key, 'category') AND is_array($val))
                {
                		foreach ($val as $k => $v)
                		{
						$catarray[$v] = $v;
					}
                }            
            }
        }
        
        if ($action == 'edit')
        {
            $catarray = array();
            
            if (is_array($selected))
            {
                foreach ($selected as $key => $val)
                {
                    $catarray[$val] = $val;
                }
            }
        }
                
        $indent = $spcr.$spcr.$spcr.$spcr;
    
        if ($depth == 1)	
        {
            $depth = 4;
        }
        else 
        {	                            
            $indent = str_repeat($spcr, $depth).$indent;
            
            $depth = $depth + 4;
        }
        
        $sel = '';
            
        foreach ($cat_array as $key => $val) 
        {
            if ($cat_id == $val['0']) 
            {
                $pre = ($depth > 2) ? "&nbsp;" : '';
                
                if ($action == 'new')
                {
                    $sel = ($default == $key) ? '1' : '';   
                }
                else
                {
                    $sel = (isset($catarray[$key])) ? '1' : '';   
                }
                
				$s = ($sel != '') ? " selected='selected'" : '';

				$this->categories[] = "<option value='".$key."'".$s.">".$pre.$indent.$spcr.$val['1']."</option>\n";

                $this->category_subtree_form($key, $cat_array, $depth, $action, $default, $selected);
            }
        }
    }
    // END        



    //---------------------------------------------------------------
    // Fetch ping servers
    //---------------------------------------------------------------
    // This function displays the ping server checkboxes
    //---------------------------------------------------------------
        
    function fetch_ping_servers($which = 'new')
    {
        global $LANG, $DB, $SESS, $DSP;

        $query = $DB->query("SELECT COUNT(*) AS count FROM exp_ping_servers WHERE member_id = '".$SESS->userdata['member_id']."'");
        
        $member_id = ($query->row['count'] == 0) ? 0 : $SESS->userdata['member_id'];
              
        $query = $DB->query("SELECT id, server_name, is_default FROM exp_ping_servers WHERE member_id = '$member_id' ORDER BY server_order");

        if ($query->num_rows == 0)
        {
            return false;
        }

		$ping_array = array();        
		
		foreach($query->result as $row)
		{
			if (isset($_POST['preview']))
			{
				$selected = '';
				foreach ($_POST as $key => $val)
				{        
					if (strstr($key, 'ping') AND $val == $row['id'])
					{
						$selected = " checked='checked' ";
						break;
					}        
				}
			}
			else
			{
				$selected = ($row['is_default'] == 'y') ? " checked='checked' " : '';
			}


			$ping_array[] = array($row['id'], $selected, $row['server_name']);
		}
		

        return $ping_array;
    }        
    // END
    

      
}
// END CLASS
?>