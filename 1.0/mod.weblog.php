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
    var $return_data    = '';     	// Final data 
    var $tb_action_id   = '';
    var	$sql			= FALSE;
    var $display_tb_rdf	= FALSE;
    var $cfields        = array();
    var $mfields        = array();
    var $categories     = array();
    
    // These are used with the nested category trees
    
    var $category_list  = array();
	var $cat_array		= array();
	var $temp_array		= array();    

	// Pagination variables
	
    var $paginate			= FALSE;
	var $field_pagination	= FALSE;
    var $paginate_data		= '';
    var $pagination_links	= '';
    var $page_next			= '';
    var $page_previous		= '';
	var $current_page		= 1;
	var $total_pages		= 1;
	var $multi_fields		= array();


    // ----------------------------------------
    //  Constructor
    // ----------------------------------------

    function Weblog()
    {   
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
    //  Weblog entries
    // ----------------------------------------

    function entries()
    {
        global $DB, $TMPL, $FNS;
         
        $this->initialize();
                                
        $this->fetch_custom_weblog_fields();
        
        $this->fetch_custom_member_fields();
        
        $this->fetch_pagination_data();
                                
        $this->build_sql_query();
        
        if ($this->sql == '')
        {
        	return;
        }
        
        $this->query = $DB->query($this->sql);
                        
        if ($this->query->num_rows == 0)
        {
            return;
        }
        
        if ( ! class_exists('Typography'))
        {
            require PATH_CORE.'core.typography'.EXT;
        }
                
        $this->TYPE = new Typography;   
                
        $this->fetch_categories();
        
        $this->tb_action_id = $FNS->fetch_action_id('Trackback_CP', 'receive_trackback');
          
        $this->parse_weblog_entries();
        
		$this->add_pagination_data();
                                
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
			
			$this->paginate			= TRUE;
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
        	$this->paginate_data = str_replace(LD.'current_page'.RD, 		$this->current_page, 		$this->paginate_data);
        	$this->paginate_data = str_replace(LD.'total_pages'.RD,			$this->total_pages,  		$this->paginate_data);
        	$this->paginate_data = str_replace(LD.'pagination_links'.RD,	$this->pagination_links,	$this->paginate_data);
        	
        	if (preg_match("/".LD."if previous_page".RD."(.+?)".LD.SLASH."if".RD."/s", $this->paginate_data, $match))
        	{
        		if ($this->page_previous == '')
        		{
        			 $this->paginate_data = preg_replace("/".LD."if previous_page".RD.".+?".LD.SLASH."if".RD."/s", '', $this->paginate_data);
        		}
        		else
        		{
					$match['1'] = preg_replace("/".LD.'path.*?'.RD."/", 	$this->page_previous, $match['1']);
					$match['1'] = preg_replace("/".LD.'auto_path'.RD."/",	$this->page_previous, $match['1']);
				
					$this->paginate_data = str_replace($match['0'],	$match['1'], $this->paginate_data);
				}
        	}
        	
        	
        	if (preg_match("/".LD."if next_page".RD."(.+?)".LD.SLASH."if".RD."/s", $this->paginate_data, $match))
        	{
        		if ($this->page_next == '')
        		{
        			 $this->paginate_data = preg_replace("/".LD."if next_page".RD.".+?".LD.SLASH."if".RD."/s", '', $this->paginate_data);
        		}
        		else
        		{
					$match['1'] = preg_replace("/".LD.'path.*?'.RD."/", 	$this->page_next, $match['1']);
					$match['1'] = preg_replace("/".LD.'auto_path'.RD."/",	$this->page_next, $match['1']);
				
					$this->paginate_data = str_replace($match['0'],	$match['1'], $this->paginate_data);
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
        
        $sql = "SELECT exp_categories.cat_name, cat_image,
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
        
        $sql .= " ORDER BY exp_categories.parent_id, exp_categories.cat_name";
        
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
        global $IN, $DB, $TMPL, $SESS, $LOC, $FNS, $REGX;
        
        $entry_id		= '';
        $year			= '';
        $month			= '';
        $day			= '';
        $qtitle			= '';
        $cat_id			= '';
        $display_by		= '';
        $corder			= '';
		$current_page	= '';
		$uristr			= '';
		$total_rows		=  0;
		$offset			=  0;
		$limit			= $this->limit;
		$page_marker	= FALSE;
        $dynamic		= TRUE;
        
         
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
        
        $uristr	 = $IN->URI;

        if ($qstring == '')
			$qstring = $IN->QSTR;
        
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
	
					// We don't want pagination with the /year/month/day/ view
					$this->paginate = FALSE;
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
										
						$qstring = $REGX->trim_slashes(str_replace($match['1'], '', $qstring));
					}
				}
				
				// --------------------------------------
				//  Parse category indicator
				// --------------------------------------

				if (preg_match("#^C(\d+)#", $qstring, $match) AND $dynamic)
				{					
					$cat_id = $match['1'];	
									
					$qstring = $REGX->trim_slashes(str_replace($match['0'], '', $qstring));
				}

				// --------------------------------------
				//  Parse page number
				// --------------------------------------

				if (preg_match("#^P(\d+)|/P(\d+)#", $qstring, $match))
				{					
					$current_page = (isset($match['2'])) ? $match['2'] : $match['1'];	
										
					$uristr  = $FNS->remove_double_slashes(str_replace($match['0'], '', $uristr));
					
					$qstring = $REGX->trim_slashes(str_replace($match['0'], '', $qstring));
					
					$page_marker = TRUE;
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
					
		$base_orders = array('random', 'date', 'title', 'comment_total', 'username', 'screen_name', 'most_recent_comment');
		
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
		
		$sql_b = ($TMPL->fetch_param('category') || $cat_id != '') ? "DISTINCT(exp_weblog_titles.entry_id) " : "exp_weblog_titles.entry_id ";
				
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
        
        // Added by Vinay on 6/13/04 to support 'emailname' parameter
        if ($TMPL->fetch_param('emailname'))
        {
			$sql .= "LEFT JOIN exp_member_data ON exp_members.member_id = exp_member_data.member_id ";
        }
        // End of Vinay's addition
        
        
        $sql .= "WHERE ";
        
        // ----------------------------------------------
        // We only select entries that have not expired 
        // ----------------------------------------------
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
        
        $sql .= "exp_weblog_titles.entry_date < ".$timestamp." ";
                   
        $sql .= "AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$timestamp.") ";
         
        // ----------------------------------------------
        //  Limit query by post ID for individual entries
        // ----------------------------------------------
         
        if ($entry_id != '')
        {           
            $sql .= "AND exp_weblog_titles.entry_id = '$entry_id' ";
        }
        
        // ----------------------------------------------
        //  Exclude an individual entry
        // ----------------------------------------------
         
		if ($not_entry_id = $TMPL->fetch_param('not_entry_id'))
		{
			if (is_numeric($not_entry_id))
			{
            	$sql .= "AND exp_weblog_titles.entry_id != '$not_entry_id' ";
        	}
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
                
                if ($query->num_rows > 0)
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
       
        // -----------------------------------------------------
        //  Limit query by date contained in tag parameters
        // -----------------------------------------------------
			        
        if ($TMPL->fetch_param('year') || $TMPL->fetch_param('month') || $TMPL->fetch_param('day'))
        {
            $year	= ( ! $TMPL->fetch_param('year')) 	? date('Y') : $TMPL->fetch_param('year');
            $month	= ( ! $TMPL->fetch_param('month'))	? date('m') : $TMPL->fetch_param('month');
            $day	= ( ! $TMPL->fetch_param('day'))	? '' : $TMPL->fetch_param('day');
            
            if (strlen($month) == 1) $month = '0'.$month;
        
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
				
				if (date("I", $LOC->now) AND ! date("I", $stime))
				{ 
					$stime -= 3600;            
				}
				elseif (date("I", $stime))
				{
					$stime += 3600;           
				}
		
				$stime += $LOC->set_localized_offset();
				
				if (date("I", $LOC->now) AND ! date("I", $etime))
				{ 
					$etime -= 3600;            
				}
				elseif (date("I", $etime))
				{
					$etime += 3600;           
				}
		
				$etime += $LOC->set_localized_offset();
				
        		$sql .= " AND exp_weblog_titles.entry_date >= ".$stime." AND exp_weblog_titles.entry_date <= ".$etime." ";
            }
            else
            {
				$display_by =& $TMPL->fetch_param('display_by');

                $lim = ( ! $TMPL->fetch_param('limit')) ? '1' : $TMPL->fetch_param('limit');
                 
                // -------------------------------------------
                //  If display_by = "month"
                // -------------------------------------------                          
                 
                if ($display_by == 'month')
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
						
						$total_rows = count($distinct);
						
						$cur = ($current_page == '') ? 0 : $current_page;
						
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
                
                elseif ($display_by == 'day')
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
						
						$total_rows = count($distinct);
						
						$cur = ($current_page == '') ? 0 : $current_page;
						
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
		// Added by Vinay 3/13/04, to enable query by emailname
		// Modified on 6/13/04 to fix a bug (which I don't completely understand)
		// ----------------------------------------------
	
		if ($emailname = $TMPL->fetch_param('emailname'))
		{
			$sql .= $FNS->sql_andor_string($emailname, 'exp_member_data.m_field_id_2');
			$sql .= str_replace("AND", "OR", $FNS->sql_andor_string($emailname, 'exp_member_data.m_field_id_3'));
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
				$str .= " AND exp_weblog_titles.status != 'closed' ";
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
									$end .= "ORDER BY exp_weblog_titles.comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
								}
								
								$sort = FALSE;
					break;
				case 'username' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.username";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.username";
								}
					break;
				case 'screen_name' : 
								if ($sticky == 'off')
								{
									$end .= "ORDER BY exp_weblog_titles.screen_name";
								}
								else
								{
									$end .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.screen_name";
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
			$limit = $TMPL->fetch_param('cat_limit');
		}
		else
		{
			$limit  = ( ! $TMPL->fetch_param('limit'))  ? $this->limit : $TMPL->fetch_param('limit');
		}
        
		// ----------------------------------------
		//  Do we need pagination?
		// ----------------------------------------
		
		// We'll run the query to find out
				
		if ($this->paginate == TRUE)
		{		
			// ----------------------------------------
			//  Standard pagination - base values
			// ----------------------------------------
		
			if ($this->field_pagination == FALSE)
			{
				if ($display_by == '')
				{
					$query = $DB->query($sql_a.$sql_c.$sql);
					
					if ($query->row['count'] == 0)
					{
						$this->sql = '';
						return;
					}
				
					$total_rows = $query->row['count'];
				}
				
				$current_page = ($current_page == '' || ($limit > 1 AND $current_page == 1)) ? 0 : $current_page;
				
				if ($current_page > $total_rows)
				{
					$current_page = 0;
				}
								
				$this->current_page = floor(($current_page / $limit) + 1);
				
				$this->total_pages = intval(floor($total_rows / $limit));
			}
			else
			{
				// ----------------------------------------
				//  Field pagination - base values
				// ----------------------------------------							
						
				$query = $DB->query($sql_a.$sql_b.$sql);
				
				if ($query->num_rows == 0)
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
							
				$limit = 1;
				
				$total_rows = count($m_fields);

				$this->total_pages = $total_rows;
				
				$current_page = ($current_page == '') ? 0 : $current_page;
				
				if ($current_page > $total_rows)
				{
					$current_page = 0;
				}
				
				$this->current_page = floor(($current_page / $limit) + 1);
				
				if (isset($m_fields[$current_page]))
				{
					$TMPL->tagdata = preg_replace("/".LD."multi_field\=[\"'].+?[\"']".RD."/s", LD.$m_fields[$current_page].RD, $TMPL->tagdata);
				
					$TMPL->var_single[$m_fields[$current_page]] = $m_fields[$current_page];
				}
			}
					
			// ----------------------------------------
			//  Create the pagination
			// ----------------------------------------
			
			if ($total_rows % $limit) 
			{
				$this->total_pages++;
			}	
			
			if ($total_rows > $limit)
			{
				if ( ! class_exists('Paginate'))
				{
					require PATH_CORE.'core.paginate'.EXT;
				}
				
				$PGR = new Paginate();
				
				$basepath = $FNS->create_url($uristr, 1);
				
				// Check for URL rewriting.  If so, we need to add the
				// SELF filename to the URL
				
				if ( ! eregi(".php$", SELF) AND ! ereg(SELF, $basepath))
				{
					$basepath .= SELF.'/';
				}
												
				$first_url = (ereg("\.php/$", $basepath)) ? substr($basepath, 0, -1) : $basepath;
				
				$PGR->first_url 	= $first_url;
				$PGR->path			= $basepath;
				$PGR->prefix		= 'P';
				$PGR->total_count 	= $total_rows;
				$PGR->per_page		= $limit;
				$PGR->cur_page		= $current_page;

				$this->pagination_links = $PGR->show_links();
				
				if ((($this->total_pages * $limit) - $limit) > $current_page)
				{
					$this->page_next = $basepath.'P'.($current_page + $limit).'/';
				}
				
				if (($current_page - $limit ) >= 0) 
				{						
					$this->page_previous = $basepath.'P'.($current_page - $limit).'/';
				}
			}
			else
			{
				$current_page = '';
			}
		}
                
        // ----------------------------------------------
        //  Add Limits to query
        // ----------------------------------------------
	
		$sql .= $end;
	
		if ($display_by == '')
		{ 
			if ($this->paginate == FALSE)
				$current_page = 0;
		
			if (($year == '' AND $month == '' AND $page_marker == FALSE AND $limit != '') || $dynamic == FALSE || ($page_marker == TRUE AND $this->field_pagination != TRUE))
			{
				$offset = ( ! $TMPL->fetch_param('offset')) ? '0' : $TMPL->fetch_param('offset');
			
				$sql .= ($current_page == '') ? " LIMIT ".$offset.', '.$limit : " LIMIT ".$current_page.', '.$limit;  
			}
			elseif ($entry_id == '' AND $qtitle == '')
			{ 
				$sql .= ($current_page == '') ? " LIMIT ".$this->limit : " LIMIT ".$current_page.', '.$this->limit;
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
	
		$this->sql .= " exp_weblog_titles.entry_id, exp_weblog_titles.weblog_id, exp_weblog_titles.author_id, exp_weblog_titles.ip_address, exp_weblog_titles.title, exp_weblog_titles.url_title, exp_weblog_titles.status, exp_weblog_titles.allow_comments, exp_weblog_titles.allow_trackbacks, exp_weblog_titles.sticky, exp_weblog_titles.entry_date, exp_weblog_titles.year, exp_weblog_titles.month, exp_weblog_titles.day, exp_weblog_titles.entry_date, exp_weblog_titles.edit_date, exp_weblog_titles.recent_comment_date, exp_weblog_titles.comment_total, exp_weblog_titles.trackback_total, exp_weblog_titles.sent_trackbacks, exp_weblog_titles.recent_trackback_date,
						exp_weblogs.blog_title, exp_weblogs.blog_url, exp_weblogs.weblog_html_formatting, exp_weblogs.weblog_allow_img_urls, exp_weblogs.weblog_auto_link_urls, exp_weblogs.enable_trackbacks, exp_weblogs.trackback_field,
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
        
        foreach ($query->result as $row)
        {
        	$this->sql .= $row['entry_id'].',';
        }
        
		$this->sql = substr($this->sql, 0, -1).') '.$end;        
    }    
    // END





    // ----------------------------------------
    //   Parse weblog entries
    // ----------------------------------------

    function parse_weblog_entries()
    {
        global $DB, $TMPL, $FNS, $SESS, $LOC, $PREFS, $REGX;
        
        
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
        //  Start the main processing loop
        // ----------------------------------------

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
			
				if ($val['0'] == "if LOGGED_IN" OR $val['0'] == "if logged_in")
				{
					$rep = ($SESS->userdata['member_id'] == 0) ? '' : $val['2'];
					
					$tagdata =& str_replace($val['1'], $rep, $tagdata); 
				}
				
				// ----------------------------------------
				//   {if NOT_LOGGED_IN}
				// ----------------------------------------
	
				if ($val['0'] == "if NOT_LOGGED_IN" OR $val['0'] == "if not_logged_in")
				{
					$rep = ($SESS->userdata['member_id'] != 0) ? '' : $val['2'];
					
					$tagdata =& str_replace($val['1'], $rep, $tagdata);                 
				}
                
                // ----------------------------------------
                //   {if allow_comments}
                // ----------------------------------------
                
				if ($val['0'] == "if allow_comments")
                {                
                    $rep = ($row['allow_comments'] == 'n') ? '' : $val['2'];
					
					$tagdata =& str_replace($val['1'], $rep, $tagdata);                 
                }
                
                // ----------------------------------------
                //   {if allow_trackbacks}
                // ----------------------------------------
                
				if ($val['0'] == "if allow_trackbacks")
                {   
                    $rep = ($row['allow_trackbacks'] == 'n') ? '' : $val['2'];
					
					$tagdata =& str_replace($val['1'], $rep, $tagdata);                 
                }
                
                
                // ----------------------------------------
                //   {if comment_tb_total}
                // ----------------------------------------
                
				if ($val['0'] == "if comment_tb_total")
                {   
                	$tot = $row['comment_total'] + $row['trackback_total'];                
                
                    $rep = ($tot == 0) ? '' : $val['2'];
					
					$tagdata =& str_replace($val['1'], $rep, $tagdata);                 
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
										$path = $FNS->remove_double_slashes($FNS->create_url($match).'/C'.$v['0'].'/');
																				
										$temp = preg_replace("#".LD."path=.+?".RD."#", $path, $temp, 1);
									}
								}
								else
								{
									$path = $FNS->create_url("SITE_INDEX");
							
									$temp = preg_replace("#".LD."path=.+?".RD."#", $path, $temp);
								}
								
								$temp = preg_replace("#".LD."category_name.*?".RD."#", $v['1'], $temp);	
								$temp = preg_replace("#".LD."category_image.*?".RD."#", $v['2'], $temp);	
										
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
                        $heading_date_hourly = date('YmdH', $LOC->set_localized_time($row['entry_date']));
                                                
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
                        if ($LOC->decode_date("%w", $row['entry_date']) != $heading_flag_weekly)
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
                
                if (ereg("^entry_date", $key))
                {
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $LOC->decode_date($val, $row['entry_date']), 
                                                            $tagdata
                                                         );
                }
                
                
                // ----------------------------------------
                //  GMT date - entry date in GMT
                // ----------------------------------------
                
                if (ereg("^gmt_entry_date", $key))
                {
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $LOC->decode_date($val, $row['entry_date'], FALSE), 
                                                            $tagdata
                                                         );
                }                
                
                // ----------------------------------------
                //  parse "last edit" date
                // ----------------------------------------
                
                if (ereg("^edit_date", $key))
                {                     
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $LOC->decode_date($val, $LOC->timestamp_to_gmt($row['edit_date'])), 
                                                            $tagdata
                                                         );
                }
                
                
                // ----------------------------------------
                //  "last edit" date as GMT
                // ----------------------------------------
                
                if (ereg("^gmt_edit_date", $key))
                {                     
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $LOC->decode_date($val, $LOC->timestamp_to_gmt($row['edit_date']), FALSE), 
                                                            $tagdata
                                                         );
                }

                
                // ----------------------------------------
                //  parse expiration date
                // ----------------------------------------
                
                if (ereg("^expiration_date", $key))
                {  
                    if ($row['expiration_date'] != 0)
                    {
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $LOC->decode_date($val, $row['expiration_date']), 
                                                            $tagdata
                                                         );
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
                
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($path, 1, 0), 
														$tagdata
													 );
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
                                    
					$tagdata =& $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($path, 1, 0), 
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
                
                	$blog_url = str_replace('http://', '', $blog_url);
                	$blog_url = str_replace('www.', '', $blog_url);
                	$blog_url = current(explode("/", $blog_url));
                
                    $tagdata =& $TMPL->swap_var_single($val, $blog_url, $tagdata);
                }
                
                // ----------------------------------------
                //  {relative_url} - used by Atom feeds
                // ----------------------------------------
                
                if ($key == "relative_url")
                {
                	$blog_url = (isset($row['blog_url']) AND $row['blog_url'] != '') ? $row['blog_url'] : '';
                	$blog_url = str_replace('http://', '', $blog_url);
                	
					if ($x = strpos($blog_url, "/"))
					{
						$blog_url = substr($blog_url, $x + 1);
					}
					
					if (ereg("/$", $blog_url))
					{
						$blog_url = substr($blog_url, 0, -1);
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
                	$tot = $row['comment_total'] + $row['trackback_total'];
                
                    $tagdata =& $TMPL->swap_var_single($val, $tot, $tagdata);
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
                        
                        $categories = substr($categories, 0, -1);                           
                    }
                }
                
                
            
                $TB = array(
                             'about'        => $FNS->create_url($row['url_title']),
                             'ping'         => $FNS->fetch_site_index(1, 0).'trackback/'.$row['entry_id'].'/',
                             'title'        => $REGX->xml_convert($row['title']),
                             'identifier'   => $FNS->create_url($row['url_title']),
                             'subject'      => $REGX->xml_convert($categories),
                             'description'  => $this->TYPE->decode_pmcode($REGX->xml_convert($FNS->char_limiter((isset($row['field_id_'.$row['trackback_field']])) ? $row['field_id_'.$row['trackback_field']] : ''))),
                             'creator'      => $REGX->xml_convert(($row['screen_name'] != '') ? $row['screen_name'] : $row['username']),
                             'date'         => $LOC->set_human_time($row['entry_date'], 0, 1).' GMT'
                            );
            
                $tagdata .= $this->trackback_rdf($TB);    
                
                $this->display_tb_rdf = FALSE;        
            }
            
			
			// $tagdata = preg_replace("/".LD."if.*?".RD.".+?".LD.SLASH."if".RD."/s", '', $tagdata);
			// $tagdata = preg_replace("/".LD.".+?".RD."/", '', $tagdata);
            
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
    //  Weblog Name
    // ----------------------------------------

    function weblog_name()
    {
        global $TMPL, $DB, $LANG;

		$blog_name = $TMPL->fetch_param('weblog');

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
		        
        $tmpl = $TMPL->tagdata.'{END}';
                
		$path	= (preg_match("#".LD."path(=.+?)".RD."#", $tmpl, $match)) ? $FNS->create_url($FNS->extract_path($match['1'])) : $FNS->create_url("SITE_INDEX");		
		$tmpl	= preg_replace("#".LD."path=.+?".RD."#", '{PATH}', $tmpl);	
        $pre 	= trim(preg_replace("/(.*?)".LD."category_name".RD.".*{END}/s", "\\1", $tmpl));
        $post	= trim(preg_replace("/.*?".LD."category_name".RD."(.*?){END}/s",   "\\1", $tmpl));	
        
		$str = '';
		
		if ($TMPL->fetch_param('style') == '' OR $TMPL->fetch_param('style') == 'nested')
        {
			$this->category_tree(
									array(
											'group_id'		=> $group_id, 
											'path'			=> $path, 
											'pre'			=> $pre, 
											'post'			=> $post,
											'blog_array' 	=> '',
											'parent_only'	=> $parent_only
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
			$sql = "SELECT cat_name, cat_id FROM exp_categories WHERE group_id ='$group_id' ";
		
			if ($parent_only == TRUE)
			{
				$sql .= " AND parent_id = '0'";
			}
			
			$sql .= " ORDER BY parent_id, cat_name";
		
            $query = $DB->query($sql);
                  
            if ($query->num_rows > 0)
            {
                foreach($query->result as $row)
                { 
					$chunk = $pre.$row['cat_name'].$post;
					
					$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $path.'/C'.$row['cat_id'].'/', $chunk))."\n"; 
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
        
        $sql .= "AND exp_weblog_titles.entry_date < ".$timestamp." ";
                   
        $sql .= "AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$timestamp.") ";
		        
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
			case 'title'				: $sql .= "ORDER BY exp_weblog_titles.title";
				break;
			case 'comment_total'		: $sql .= "ORDER BY exp_weblog_titles.entry_date";
				break;
			case 'most_recent_comment'	: $sql .= "ORDER BY exp_weblog_titles.comment_date desc, exp_weblog_titles.entry_date";
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
		                
        $cat_chunk  = (preg_match("/".LD."categories\s*".RD."(.*?)".LD.SLASH."categories\s*".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : '';        
        $cat_chunk .='{END}';
		$c_path	= (preg_match("#".LD."path(=.+?)".RD."#", $cat_chunk, $match)) ?  $FNS->create_url($FNS->extract_path($match['1'])) : $FNS->create_url("SITE_INDEX");			
		$cat_chunk = preg_replace("#".LD."path=.+?".RD."#", '{PATH}', $cat_chunk);	
        $c_pre 	= trim(preg_replace("/(.*?)".LD."category_name".RD.".*{END}/s", "\\1", $cat_chunk));
        $c_post	= trim(preg_replace("/.*?".LD."category_name".RD."(.*?){END}/s",   "\\1", $cat_chunk));	
        
        $tit_chunk = (preg_match("/".LD."entry_titles\s*".RD."(.*?)".LD.SLASH."entry_titles\s*".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : '';        
        $tit_chunk .= ($tit_chunk == '') ? '' : '{END}';
		$t_path	= (preg_match("#".LD."path(=.+?)".RD."#", $cat_chunk, $match)) ?  $FNS->create_url($FNS->extract_path($match['1'])) : $FNS->create_url("SITE_INDEX");		
		$tit_chunk = preg_replace("#".LD."path=.+?".RD."#", '{PATH}', $tit_chunk);	
        $t_pre 	= trim(preg_replace("/(.*?)".LD."title".RD.".*{END}/s", "\\1", $tit_chunk));
        $t_post	= trim(preg_replace("/.*?".LD."title".RD."(.*?){END}/s",   "\\1", $tit_chunk));	
                
		$str = '';
				
		if ($TMPL->fetch_param('style') == '' OR $TMPL->fetch_param('style') == 'nested')
        {
        	if ($result->num_rows > 0 && $tit_chunk != '')
        	{        		
        		$i = 0;	
				foreach($result->result as $row)
				{
					$chunk = "<li>".$t_pre.$row['title'].$t_post."</li>";
					$blog_array[$i.'_'.$row['cat_id']] = $FNS->remove_double_slashes(str_replace("{PATH}", $t_path.'/'.$row['entry_id'].'/', $chunk))."\n"; 
					$i++;
				}
			}
			
			$this->category_tree(
									array(
											'group_id'		=> $group_id, 
											'path'			=> $c_path, 
											'pre'			=> $c_pre, 
											'post'			=> $c_post,
											'blog_array' 	=> $blog_array
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
            $query = $DB->query("SELECT cat_name, cat_id FROM exp_categories WHERE group_id ='$group_id' ORDER BY parent_id, cat_name");
                  
            if ($query->num_rows > 0)
            {
                foreach($query->result as $row)
                { 
					$chunk = $c_pre.$row['cat_name'].$c_post;
					
					$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $c_path.'/C'.$row['cat_id'].'/', $chunk))."\n"; 
					
					foreach($result->result as $trow)
					{
						if ($trow['cat_id'] == $row['cat_id'])
						{
							$chunk = $t_pre.$trow['title'].$t_post;
							$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $t_path.'/'.$trow['entry_id'].'/', $chunk))."\n"; 
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
    // Category Tree
    //--------------------------------

    // This function and the next create a nested, hierarchical category tree

    function category_tree($cdata = array())
    {  
        global $FNS, $REGX, $DB, $TMPL, $FNS;
        
        $default = array('group_id', 'path', 'pre', 'post', 'depth', 'blog_array', 'parent_only');
        
        foreach ($default as $val)
        {
        	$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
        }
        
        if ($group_id == '')
        {
            return false;
        }
        
        $sql = "SELECT cat_name, cat_id, parent_id FROM exp_categories WHERE group_id ='$group_id' ";
        
        if ($parent_only === TRUE)
        {
        	$sql .= " AND parent_id = 0";
        }
        
        $sql .= " ORDER BY parent_id, cat_name";
        
        $query = $DB->query($sql);
              
        if ($query->num_rows == 0)
        {
            return false;
        }
                            
        foreach($query->result as $row)
        {        
            $this->cat_array[$row['cat_id']]  = array($row['parent_id'], $row['cat_name']);
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
            	
            	$chunk = "\t<li>".$pre.$val['1'].$post;
            	            	
            	$this->category_list[] = $FNS->remove_double_slashes(str_replace("{PATH}", $path.'/C'.$key.'/', $chunk)); 
            	
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
													'pre'			=> $pre, 
													'post'			=> $post,
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
        
        $default = array('parent_id', 'path', 'pre', 'post', 'depth', 'blog_array');
        
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
            	
            	$chunk = "<li>".$pre.$val['1'].$post;
            	
            	$chunk = $FNS->remove_double_slashes(str_replace("{PATH}", $path.'/C'.$key.'/', $chunk)); 
            	
            	$this->category_list[] = $tab."\t".$chunk;
            	
            	
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
													'pre'			=> $pre, 
													'post'			=> $post,
													'depth' 		=> $depth + 2,
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

		if ( ! preg_match("#^C(\d+)#", $qstring, $match))
		{					
			return '';
		}
				
		$query = $DB->query("SELECT cat_name, cat_image FROM exp_categories WHERE cat_id = '".$DB->escape_str($match['1'])."'");
		
		if ($query->num_rows == 0)
		{
			return '';
		}

		$TMPL->tagdata = preg_replace("/".LD."category_image\s*".RD."/s", $query->row['cat_image'], $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("/".LD."category_name\s*".RD."/s",  $query->row['cat_name'],  $TMPL->tagdata);	

		return $TMPL->tagdata;
    }
    // END
    
    


    // ----------------------------------------
    //  Weblog "next entry" link
    // ----------------------------------------

    function next_entry()
    {
        global $IN, $TMPL, $LOC, $FNS, $REGX, $DB, $LANG, $FNS;
        
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
           

		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		
        $sql .= " AND t1.entry_date < ".$timestamp." ";
        		
		$sql .= " AND t1.entry_date > t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
        		
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
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("#".LD."title".RD."#", preg_quote($query->row['title']), $TMPL->tagdata);	

        return $FNS->remove_double_slashes(stripslashes($TMPL->tagdata));
    }
    // END




    // ----------------------------------------
    //  Weblog "previous entry" link
    // ----------------------------------------

    function prev_entry()
    {
        global $IN, $TMPL, $LOC, $FNS, $REGX, $DB, $LANG;
        
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
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;

        $sql .= " AND t1.entry_date < ".$timestamp." ";

		$sql .= " AND t1.entry_date < t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
        
        		
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
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("#".LD."title".RD."#", preg_quote($query->row['title']), $TMPL->tagdata);	

        return $FNS->remove_double_slashes(stripslashes($TMPL->tagdata));
    }
    // END



    // ----------------------------------------
    //  Weblog "month links"
    // ----------------------------------------

    function month_links()
    {
        global $TMPL, $LOC, $FNS, $REGX, $DB, $LANG;
        
        $return = '';
        
        // ----------------------------------------
        //  Build query
        // ----------------------------------------
        			
		$localtime = $LOC->set_localized_time($LOC->set_gmt(mktime(date('H'), date('G'), date('i'), date('m'), date('d'), date('Y'))));          
        
        $sql = "SELECT DISTINCT month, year
                FROM exp_weblog_titles 
                WHERE (expiration_date = 0 || expiration_date > UNIX_TIMESTAMP()) 
                AND exp_weblog_titles.entry_date < '$localtime' "; 

          
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
            
            $month = $LOC->localize_month($row['month']);          
            
            // ----------------------------------------
            //  parse path
            // ----------------------------------------
            
            foreach ($TMPL->var_single as $key => $val)
            {              
                if (ereg("^path", $key))
                {                    
                    $tagdata =& $TMPL->swap_var_single(
                                                        $val, 
                                                        $FNS->create_url($FNS->extract_path($key).'/'.$row['year'].'/'.$row['month']), 
                                                        $tagdata
                                                      );
                }

                // ----------------------------------------
                //  parse month (long)
                // ----------------------------------------
                
                if ($key == 'month')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $LANG->line($month['1']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (short)
                // ----------------------------------------
                
                if ($key == 'month_short')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $LANG->line($month['0']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (numeric)
                // ----------------------------------------
                
                if ($key == 'month_num')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $row['month'], $tagdata);
                }
                
                // ----------------------------------------
                //  parse year
                // ----------------------------------------
                
                if ($key == 'year')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, $row['year'], $tagdata);
                }
                
                // ----------------------------------------
                //  parse year (short)
                // ----------------------------------------
                
                if ($key == 'year_short')
                {    
                    $tagdata =& $TMPL->swap_var_single($key, substr($row['year'], 2), $tagdata);
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
		
		// ----------------------------------
		// Find Categories for Entry
		// ----------------------------------
		
		$sql = "SELECT exp_categories.cat_id, exp_categories.cat_name
				FROM exp_weblog_titles
				INNER JOIN exp_category_posts ON exp_weblog_titles.entry_id = exp_category_posts.entry_id
				INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id 
				WHERE exp_categories.cat_id IS NOT NULL ";
	
		$sql .= ( ! is_numeric($qstring)) ? "AND exp_weblog_titles.url_title = '{$qstring}' " : "AND exp_weblog_titles.entry_id = '{$qstring}'";
		
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
			$adjusted_date = $LOC->adjust_date($month - 1, $year, TRUE);
					
			foreach ($matches['1'] as $match)
			{
				$path = $FNS->create_url($match).$adjusted_date['year'].'/'.$adjusted_date['month'].'/';
				
				$TMPL->tagdata = preg_replace("#".LD."previous_path=.+?".RD."#", $path, $TMPL->tagdata, 1);
			}
		}
		
		// ----------------------------------------
		//  {next_path="weblog/index"}
		// ----------------------------------------
		
		// This variables points to the next month

		if (preg_match_all("#".LD."next_path=(.+?)".RD."#", $TMPL->tagdata, $matches))
		{
			$adjusted_date = $LOC->adjust_date($month + 1, $year, TRUE);
					
			foreach ($matches['1'] as $match)
			{
				$path = $FNS->create_url($match).$adjusted_date['year'].'/'.$adjusted_date['month'].'/';
				
				$TMPL->tagdata = preg_replace("#".LD."next_path=.+?".RD."#", $path, $TMPL->tagdata, 1);
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
				$TMPL->tagdata = preg_replace("#".LD."date format=.+?".RD."#", $LOC->decode_date($match, $local_date), $TMPL->tagdata, 1);
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
				$chunk = trim($match['1'])."\n";
				
				$chunk = str_replace(LD.'lang:weekday_abrev'.RD, $day_names_a[($start_day + $i) %7], $chunk);
				$chunk = str_replace(LD.'lang:weekday_short'.RD, $day_names_s[($start_day + $i) %7], $chunk);
				$chunk = str_replace(LD.'lang:weekday_long'.RD,  $day_names_l[($start_day + $i) %7], $chunk);
			
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
										'entry_date'		=> 'a',
										'permalink'			=> 'a',
										'title_permalink'	=> 'a',
										'author'			=> 's',
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
									
						if (ereg("^entry_date", $key))
						{
							$entry_date[$key] = $LOC->decode_date($val, $row['entry_date']);
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
						
						$out = str_replace(LD.'day_path'.RD, $data[$day]['0']['10'], $out);
					}
					else
					{
						$out .= str_replace($if_not_entries_m, $if_not_entries, $row_chunk);
					}
					
					$out = str_replace(LD.'day_number'.RD, $day, $out);
					
					
					if ($day == $today["mday"] AND $month == $today["mon"] AND $year == $today["year"])
					{
						$out = str_replace(LD.'switch'.RD, $switch_t, $out);
					}
					else
					{
						$out = str_replace(LD.'switch'.RD, $switch_c, $out);
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
		$out = str_replace($entries_m,			'', $out);
		$out = str_replace($if_blank_m,			'', $out);
		$out = str_replace($if_today_m,			'', $out);
		$out = str_replace($if_entries_m,		'', $out);
		$out = str_replace($if_entries_m,		'', $out);
		$out = str_replace($if_not_entries_m,	'', $out);

    	
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
				
				$str = str_replace(LD.'title'.RD,				$val['0'], $str);
				$str = str_replace(LD.'url_title'.RD,			$val['1'], $str);
				$str = str_replace(LD.'author'.RD,				$val['5'], $str);
				$str = str_replace(LD.'comment_tb_total'.RD,	$val['9'], $str);
				$str = str_replace(LD.'day_path'.RD,			$val['10'], $str);
				

				// Entry Date
				foreach ($val['2'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
				
				// Permalink
				foreach ($val['3'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
				
				// Title permalink
				foreach ($val['4'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
				
				// Profile path
				foreach ($val['6'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
				
				// ID path
				foreach ($val['7'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
				
				// Base Fields
				foreach ($val['8'] as $k => $v)
				{
					$str = str_replace(LD.$k.RD, $v, $str);
				}
			
				$temp .= $str;
			}
			
			$chunk = str_replace('Gm983TGxkedSPoe0912NNk', $temp, $chunk);
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
      
}
// END CLASS
?>