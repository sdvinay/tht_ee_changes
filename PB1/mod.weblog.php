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
 Purpose: Weblog class.
=====================================================
*/

if ( ! defined('EXT'))
{
    exit('Invalid file request');
}



class Weblog {

    var $sql            = '';
    var $query;
    var $TYPE;  
    var $return_data    = '';     	// Final data 
    var $limit          = '1000';   // Default maximum query results
    var $tb_action_id   = '';
    var $display_tb_rdf	= FALSE;  	// This is set dynamically
    var $cfields        = array();
    var $mfields        = array();
    var $categories     = array();
    
    // These are used with the nested category trees
    
    var $category_list  = array();
	var $cat_array		= array();
	var $temp_array		= array();    




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
        $this->sql = '';
        $this->return_data = '';
    }
    // END
    

    // ----------------------------------------
    //  Weblog entries
    // ----------------------------------------

    function entries()
    {
        global $DB, $FNS;
                
        $this->initialize();
        
        $this->fetch_custom_weblog_fields();
        
        $this->fetch_custom_member_fields();
                                
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
                
        $this->fetch_categories();
        
        $this->tb_action_id = $FNS->fetch_action_id('Trackback_CP', 'receive_trackback');
     
        $this->parse_weblog_entries();
                        
        return $this->return_data;        
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
    // END



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

    function build_sql_query()
    {
        global $IN, $DB, $TMPL, $SESS, $LOC, $FNS;
        
        $entry_id    = '';
        $year       = '';
        $month      = '';
        $day        = '';
        $qtitle     = '';
        $cat_id     = '';
        $display_by = '';
        $dynamic	= TRUE;
 
        // ----------------------------------------------
        //  Is the dynamic='off' set?
        // ----------------------------------------------
        
        // If so, we'll override all dynamicaly set variables
        
		if ($TMPL->fetch_param('dynamic') == 'off')
		{		
			$dynamic = FALSE;
		}  
				
                
        // ----------------------------------------------
        //  Parse the URL query string
        // ----------------------------------------------
        
		if ($IN->QSTR != '')
		{
			if (is_numeric($IN->QSTR) AND $dynamic)
			{
				$entry_id = &$IN->QSTR;
			}
			else
			{
				if (ereg("/", $IN->QSTR))
				{
					if (preg_match("#\d{3}/\d{2}#", $IN->QSTR))
					{					
						$ex = explode('/', $IN->QSTR);
						
						$year =  $LOC->set_partial_gmt('y', $ex['0']);
						$month = $LOC->set_partial_gmt('m', '', $ex['1']);  
						
						if (isset($ex['2']) AND strlen($ex['2']) == 2)
						{
							$day = $LOC->set_partial_gmt('d', '', '', $ex['2']);
						}
					}
				}
				elseif (ereg("^C", $IN->QSTR) AND $dynamic)
				{
					$cat_id = substr($IN->QSTR, 1);
				}
				else
				{
					$sql = "SELECT count(*) AS count 
							FROM  exp_weblog_titles, exp_weblogs 
							WHERE exp_weblog_titles.weblog_id = exp_weblogs.weblog_id
							AND   exp_weblog_titles.url_title = '".$IN->QSTR."'";
					
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
						$qtitle = &$IN->QSTR;
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
        //  Build the master SQL query
        // ----------------------------------------------
            
	// Vinay, 3/6/04
	// Added exp_members.bio to this query so that we can
	// access author bios using {exp:weblog:entries} and {bio}
        $this->sql = "SELECT exp_weblog_titles.*,
                             exp_weblog_data.*,
                             exp_member_data.*,
                             exp_weblogs.blog_title, exp_weblogs.weblog_html_formatting, exp_weblogs.weblog_allow_img_urls, exp_weblogs.weblog_auto_link_urls, exp_weblogs.enable_trackbacks, exp_weblogs.trackback_field,
                             exp_members.username, exp_members.email, exp_members.url, exp_members.screen_name, exp_members.group_id, exp_members.member_id, exp_members.bio
                      FROM exp_weblog_titles
                      LEFT JOIN exp_weblogs ON exp_weblog_titles.weblog_id = exp_weblogs.weblog_id 
                      LEFT JOIN exp_weblog_data ON exp_weblog_titles.entry_id = exp_weblog_data.entry_id 
                      LEFT JOIN exp_members ON exp_members.member_id = exp_weblog_titles.author_id 
                      LEFT JOIN exp_member_data ON exp_member_data.member_id = exp_members.member_id ";
                      
        if ($TMPL->fetch_param('category') || $cat_id != '')                      
        {
            $this->sql .= "LEFT JOIN exp_category_posts ON exp_weblog_titles.entry_id = exp_category_posts.entry_id
                           LEFT JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
        }
        
        $this->sql .= "WHERE ";
        
        // ----------------------------------------------
        // We only select entries that have not expired 
        // ----------------------------------------------
        
        $this->sql .= "exp_weblog_titles.entry_date < ".$LOC->now." ";
                   
        $this->sql .= "AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$LOC->now.") ";
         
        // ----------------------------------------------
        //  Limit query by post ID for individual entries
        // ----------------------------------------------
         
        if ($entry_id != '')
        {           
            $this->sql .= "AND exp_weblog_titles.entry_id = '$entry_id' ";
        }
         
        // ----------------------------------------------
        // Limit to/exclude specific weblogs
        // ----------------------------------------------
    
        if (USER_BLOG !== FALSE)
        {
            // If it's a "user blog" we limit to only their assigned blog
        
            $this->sql .= "AND exp_weblogs.weblog_id = '".UB_BLOG_ID."' ";
        }
        else
        {
            $this->sql .= "AND exp_weblogs.is_user_blog = 'n' ";
        
            if ($weblog = $TMPL->fetch_param('weblog'))
            {
                $sql = "SELECT weblog_id FROM exp_weblogs WHERE ";
            
                $str = $FNS->sql_andor_string($weblog, 'blog_name');
                
                if (substr($str, 0, 3) == 'AND')
                    $str = substr($str, 3);
                
                $sql .= $str;            
                    
                $query = $DB->query($sql);
                
                if ($query->num_rows > 0)
                {
                    if ($query->num_rows == 1)
                    {
                        $this->sql .= "AND exp_weblog_titles.weblog_id = '".$query->row['weblog_id']."' ";
                    }
                    else
                    {
                        $this->sql .= "AND (";
                        
                        foreach ($query->result as $row)
                        {
                            $this->sql .= "exp_weblog_titles.weblog_id = '".$row['weblog_id']."' OR ";
                        }
                        
                        $this->sql = substr($this->sql, 0, - 3);
                        
                        $this->sql .= ") ";
                    }
                }
            }
        }
                  
       
        // -----------------------------------------------------
        //  Limit query by date contained in tag parameters
        // -----------------------------------------------------
        
        if ($TMPL->fetch_param('year') || $TMPL->fetch_param('month') || $TMPL->fetch_param('day'))
        {
            $p_year  = ( ! $TMPL->fetch_param('year')) ? '' : $TMPL->fetch_param('year');
            
            $p_year = $LOC->set_partial_gmt('y', $p_year);
            
            $this->sql .= "AND exp_weblog_titles.year = '$p_year' ";
            
            $p_month = $TMPL->fetch_param('month');
            
            if (strlen($p_month) == 1) $p_month = '0'.$p_month;
            
            if ($p_month)
            {
                $p_month = $LOC->set_partial_gmt('m', '', $p_month);
            
                $this->sql .= "AND exp_weblog_titles.month = '$p_month' ";
            } 
                        
            if ($day = $TMPL->fetch_param('day'))
            {
                if ( ! $p_month)
                {
                    $p_month = ( ! $TMPL->fetch_param('month')) ? '' : $TMPL->fetch_param('month');
                    
                    if (strlen($p_month) == 1) $p_month = '0'.$p_month;
                    
                    $p_month = $LOC->set_partial_gmt('m', '', $p_month);
                
                    $this->sql .= "AND exp_weblog_titles.month = '$p_month' ";
                }
                
                if (strlen($day) == 1) $day = '0'.$day;
                
                $day = $LOC->set_partial_gmt('d', '', '', $day);
            
                $this->sql .= "AND exp_weblog_titles.day = '$day' ";
            } 
        }
        else
        {
        
            // ------------------------------------------------
            //  Limit query by date in URI: /2003/12/14/
            // -------------------------------------------------

            if ($year != '' AND $month != '')
            {
				if ($dynamic)
				{
					$this->sql .= "AND exp_weblog_titles.year = '$year' AND exp_weblog_titles.month = '$month'";
					
					if ($day != '')
					{
						$this->sql .= "AND exp_weblog_titles.day = '$day'";
					}
				}
            }
            else
            {
                // -------------------------------------------------------------
                //  Display by x number of months or days (display_by="month")
                // -------------------------------------------------------------
            
                $display_by = $TMPL->fetch_param('display_by');
                
                $lim = ( ! $TMPL->fetch_param('limit')) ? '1' : $TMPL->fetch_param('limit');
                 
                if ($display_by == 'month')
                {            
                    $time = $this->calculate_display_offset('month', $lim);
                
                    $this->sql .= "AND exp_weblog_titles.entry_date >= '$time' ";
                }
                elseif ($display_by == 'day')
                {
                    $time = $this->calculate_display_offset('day', $lim);
                
                    $this->sql .= "AND exp_weblog_titles.entry_date >= '$time' ";
                }
            }
        }
        
        
        // ----------------------------------------------
        //  Limit query "URL title"
        // ----------------------------------------------
         
        if ($qtitle != '' AND $dynamic)
        {    
			$this->sql .= "AND exp_weblog_titles.url_title = '$qtitle' ";
        }
        
        // ----------------------------------------------
        //  Limit query by category
        // ----------------------------------------------
                
        if ($TMPL->fetch_param('category'))
        {
            $this->sql .= $FNS->sql_andor_string($TMPL->fetch_param('category'), 'exp_categories.cat_id');
        }
        else
        {
            if ($cat_id != '' AND $dynamic)
            {           
                $this->sql .= "AND exp_categories.cat_id = '$cat_id' ";
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
                $this->sql .=  "AND exp_members.member_id = '".$SESS->userdata['member_id']."' ";
            }
            elseif ($username == 'NOT_CURRENT_USER')
            {
                $this->sql .=  "AND exp_members.member_id != '".$SESS->userdata['member_id']."' ";
            }
            else
            {                
                $this->sql .= $FNS->sql_andor_string($username, 'exp_members.username');
            }
        }

        // ----------------------------------------------
	// Added by Vinay 3/13/04, to enable query by emailname
	// ----------------------------------------------

	if ($emailname = $TMPL->fetch_param('emailname'))
	{
		$this->sql .= ' AND (exp_member_data.m_field_id_2 = \'';
		$this->sql .= $emailname;
		$this->sql .= '\' || exp_member_data.m_field_id_3 = \'';
		$this->sql .= $emailname . '\') ';
	}
	// End of Vinay's addition
    
        // ----------------------------------------------
        // Add status declaration to the query
        // ----------------------------------------------
        
		$this->sql .= "AND exp_weblog_titles.status != 'closed' ";
        
        if ($status = $TMPL->fetch_param('status'))
        {
        	$status = str_replace('Open',   'open',   $status);
        	$status = str_replace('Closed', 'closed', $status);
        
            $this->sql .= $FNS->sql_andor_string($status, 'exp_weblog_titles.status');
        }
        else
        {
            $this->sql .= "AND exp_weblog_titles.status = 'open' ";
        }
            
            
        // ----------------------------------------------
        //  Add Group ID clause
        // ----------------------------------------------
        
        if ($group_id = $TMPL->fetch_param('group_id'))
        {
            $this->sql .= $FNS->sql_andor_string($group_id, 'exp_members.group_id');
        }
        
        
        // --------------------------------------------------
        //  Check for empty result do to "display_by" param
        // --------------------------------------------------
		// If the display_by parameter is being used, we'll run the query to see if
		// it produces a result.  If not, we'll fetch the date of the last posted entry
		// and re-adjust the query based on this date.  We do this so that the weblog will 
		// never appear blank if the user is displaying by day or month and they neglected to update it
        
		if ($display_by != '')
		{
			if (preg_match("/AND exp_weblog_titles\.entry_date >= \'\d+?\'/", $this->sql, $match))
			{
				$query = $DB->query($this->sql);
				
				if ($query->num_rows == 0)
				{				
					$this->sql = preg_replace("/AND exp_weblog_titles\.entry_date >= \'\d+?\'/", '', $this->sql);
				
					preg_match("/\'(\d+?)\'/", $match['0'], $ts);
				
					$sql = $this->sql;
					
					$sql .= "AND exp_weblog_titles.entry_date < '".$ts['1']."' LIMIT 1";
				
					$query = $DB->query($sql);
					
					if ($query->num_rows > 0)
					{					 
						$display_by = $TMPL->fetch_param('display_by');
						
						$lim = ( ! $TMPL->fetch_param('limit')) ? '1' : $TMPL->fetch_param('limit');
												 
						if ($display_by == 'month')
						{            
							$time = $this->calculate_display_offset('month', $lim, $query->row['entry_date']);
						
							$this->sql .= "AND exp_weblog_titles.entry_date >= '$time' ";
						}
						elseif ($display_by == 'day')
						{
							$time = $this->calculate_display_offset('day', $lim, $query->row['entry_date']);
						
							$this->sql .= "AND exp_weblog_titles.entry_date >= '$time' ";
						}
					}
				}
			}
		}
        
              
        // --------------------------------------------------
        // Add sorting and limiting clauses in multi-entries
        // --------------------------------------------------
    
        if ($entry_id == '')
        {        
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
        
        
            if (FALSE === $order)
            {
				if ($sticky == 'off')
				{
					$this->sql .= "ORDER BY exp_weblog_titles.entry_date";
				}
				else
				{
					$this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.entry_date";
				}
            }
            else
            {
                switch ($order)
                {
                    case 'date' : 
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.entry_date";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.entry_date";
                                    }
                        break;
                    case 'title' : 
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.title";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.title";
                                    }
                        break;
                    case 'comment_total' : 
                    
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.comment_total {$sort}, exp_weblog_titles.entry_date {$sort}";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.comment_total {$sort}, exp_weblog_titles.entry_date {$sort}";
                                    }
                                    
                                    $sort = FALSE;
                        break;
                    case 'most_recent_comment' : 
                    
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.comment_date {$sort}, exp_weblog_titles.entry_date {$sort}";
                                    }
                                    
                                    $sort = FALSE;
                        break;
                    case 'username' : 
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.username";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.username";
                                    }
                        break;
                    case 'screen_name' : 
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.screen_name";
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc, exp_weblog_titles.screen_name";
                                    }
                        break;
                    case 'custom_field' : 
                                    if ($sticky == 'off')
                                    {
                                        $this->sql .= "ORDER BY  exp_weblog_data.field_id_".$corder;
                                    }
                                    else
                                    {
                                        $this->sql .= "ORDER BY exp_weblog_titles.sticky desc,  exp_weblog_data.field_id_".$corder;
                                    }
                        break;
                    case 'random' : 
                                    $this->sql .= "ORDER BY rand()";  
                                    $sort = FALSE;
                        break;
                    default       : $this->sql .= "ORDER BY exp_weblog_titles.entry_date";
                        break;
                }
            
                    
                if ($sort == 'asc' || $sort == 'desc')
                {
                    $this->sql .= " $sort";
                }
            }
                        
			$offset = ( ! $TMPL->fetch_param('offset')) ? '0' : $TMPL->fetch_param('offset');
			$limit  = ( ! $TMPL->fetch_param('limit'))  ? $this->limit : $TMPL->fetch_param('limit');
		
			if ($display_by == '')
			{
				if (($year == '' AND $month == '') || $dynamic == FALSE)
				{
					$this->sql .= " LIMIT ".$offset.', '.$limit;  
				}
				else
				{
					$this->sql .= " LIMIT ".$this->limit;
				}
			}			
        }        
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
        
        // We'll grab the category data now to avoid processing cycles
        // in the foreach loop below
        
        $cat_chunk = array();
        
        if (preg_match_all("/".LD."categories.*?".RD."(.*?)".LD.SLASH.'categories'.RD."/s", $TMPL->tagdata, $matches))
        {
			foreach ($matches['1'] as $val)
			{
				$cat_name  = (preg_match("/".LD."category_name(.*?)".RD."/", $val, $match)) ? $match['1'] : '';
				$cat_image = (preg_match("/".LD."category_image(.*?)".RD."/", $val, $match)) ? $match['1'] : '';
				
				$cat_nparams =& $TMPL->assign_parameters($cat_name);
				$cat_iparams =& $TMPL->assign_parameters($cat_image);
			
				$cat_chunk[] = array($val, $cat_nparams, $cat_iparams);
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

            foreach ($TMPL->var_cond as $key => $val)
            {
            
                // ----------------------------------------
                //   {if LOGGED_IN}
                // ----------------------------------------
            
                if (preg_match("/^if\s+LOGGED_IN.*/i", $key))
                {
                    if ($SESS->userdata['member_id'] == 0)
                    {
                        $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                    }
                    else
                    {
                        $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                    } 
                }
                
                // ----------------------------------------
                //   {if NOT_LOGGED_IN}
                // ----------------------------------------

                if (preg_match("/^if\s+NOT_LOGGED_IN.*/i", $key))
                {
                    if ($SESS->userdata['member_id'] != 0)
                    {
                        $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                    }
                    else
                    {
                        $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                    }  
                }
                
                
                // ----------------------------------------
                //   {if allow_comments}
                // ----------------------------------------

                if (preg_match("/^if\s+allow_comments.*/i", $key))
                {                
                    if ($row['allow_comments'] == 'n')
                    {
                        $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                    }
                    else
                    {
                        $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                    }  
                }
                
                // ----------------------------------------
                //   {if allow_trackbacks}
                // ----------------------------------------

                if (preg_match("/^if\s+allow_trackbacks.*/i", $key))
                {                
                    if ($row['allow_trackbacks'] == 'n')
                    {
                        $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                    }
                    else
                    {
                        $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                    }  
                }
                
                // ----------------------------------------
                //   Conditional statements
                // ----------------------------------------
                
                // The $key variable contains the full contitional statement.
                // For example: if username != 'joe'
                
                // First, we'll remove "if" from the statement, otherwise,
                // eval() will return an error 
                
                $cond = preg_replace("/^if/", "", $key);
                
                // Since we allow the following shorthand condition: {if username}
                // but it's not legal PHP, we'll correct it by adding:  != ''
                
                if ( ! ereg("\|", $cond))
                {                    
                    if ( ! preg_match("/(\!=|==|<|>|<=|>=|<>)/s", $cond))
                    {
                        $cond .= " != ''";
                    }
                }
                

                foreach ($val as $field)
                {      
                                
                    // ----------------------------------------
                    //  Parse conditions in standard fields
                    // ----------------------------------------
                
                    if ( isset($row[$field]))
                    {       
                        $cond =& str_replace($field, "\$row['".$field."']", $cond);
                          
                        $cond =& str_replace("\|", "|", $cond);
                                 
                        eval("\$result = ".$cond.";");
                                            
                        if ($result)
                        {
                            $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                        }
                        else
                        {
                            $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                        }   
                    }
                    else
                    {  
                    
                        // ------------------------------------------
                        //  Parse conditions in custom weblog fields
                        // ------------------------------------------
                                        
                        if (isset( $this->cfields[$field]))
                        {
                            if (isset($row['field_id_'.$this->cfields[$field]]))
                            {
                                $v = $row['field_id_'.$this->cfields[$field]];
                                             
                                $cond =& str_replace($field, "\$v", $cond);
                                
                                $cond =& str_replace("\|", "|", $cond);
                                         
                                eval("\$result = ".$cond.";");
                                                    
                                if ($result)
                                {
                                    $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                                }
                                else
                                {
                                    $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                                }   
                            }
                        }
                        
                        // ------------------------------------------
                        //  Parse conditions in custom member fields
                        // ------------------------------------------

                        elseif (isset( $this->mfields[$field]))
                        {
                            if (isset($row['m_field_id_'.$this->mfields[$field]]))
                            {
                                $v = $row['m_field_id_'.$this->mfields[$field]];
                                             
                                $cond =& str_replace($field, "\$v", $cond);
                                
                                $cond =& str_replace("\|", "|", $cond);
                                         
                                eval("\$result = ".$cond.";");
                                                    
                                if ($result)
                                {
                                    $tagdata =& $TMPL->swap_var_pairs($key, 'if', $tagdata);
                                }
                                else
                                {
                                    $tagdata =& $TMPL->delete_var_pairs($key, 'if', $tagdata);
                                }   
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
										$path = $FNS->create_url($match).'/C'.$v['0'].'/';
										
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
							
							if (is_array($catval['2']) AND isset($catval['2']['backspace']))
							{
								$cats =& substr($cats, 0, - $catval['2']['backspace']);
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
                
                if (ereg("^comment_path", $key) || ereg("^trackback_path", $key) )
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
                
                if (ereg("^title_permalink", $key))
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
                //  parse title
                // ----------------------------------------
                
                if (ereg("^title$", $key))
                {                
                        $tagdata =& $TMPL->swap_var_single(
                                                            $key, 
                                                            $this->TYPE->parse_type( $row[$val], array('text_format' => 'lite', 'html_format' => 'safe', 'auto_links' => 'n', 'allow_img_url' => 'n')),
                                                            $tagdata
                                                         );
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
               
                // ------------------------------------------------
                //  parse simple conditionals: {body|more|summary}
                // ------------------------------------------------
                
                if (ereg('\|', $key))
                {                
                    if (is_array($val))
                    {                
                        foreach($val as $item)
                        {
                            // Basic fields
                                        
                            if (isset($row[$val]))
                            {                    
                                $tagdata =& $TMPL->swap_var_single($val, $row[$val], $tagdata);
                                
                                continue;
                            }
            
                            // Custom weblog fields
                            
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
                                                                 
                                continue;                                                               
                            }
                        }
                    }
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
                

            $this->return_data .= $tagdata;
            
        }
        // END FOREACH LOOP
    }
    // END


    // ----------------------------------------
    //  Calculate display offset
    // ----------------------------------------
    
    // This function calculates the number of seconds between the last minute
    // in the current month and the first minute of the day or month in the past
    // being requested via the display param. 

    function calculate_display_offset($display_by, $limit = 1, $ts = '')
    {
        global $LOC;
        
        if ($ts == '')
        	$ts = time();
        
        $hour  = '23';
        $min   = '59';
        $sec   = '59';
        $month = date('m', $ts);
        $day   = date('d', $ts);
        $year  = date('Y', $ts);
        
        $midnight = ($ts == '') ? $LOC->set_gmt(mktime($hour, $min, $sec, $month, $day, $year)) : mktime($hour, $min, $sec, $month, $day, $year);
        
        $oneday = 60*60*24;
        
        if ($display_by == 'day')
        {
            $total_seconds = $midnight - ($oneday * $limit);
        }
        elseif ($display_by == 'month')
        {
            $hour  = '00';
            $min   = '00';
            $sec   = '00';
        
            $numdays = date('t');
            
            $day = ($numdays - $day) * $oneday;
                
            $n = &$limit;
            
            $tot = 0;
            
            while ($n > 0)
            {
                $time = mktime($hour, $min, $sec, $month, $day, $year);
            
                $tot = $tot + date('t', $time);
            
                if ($month == 0)
                {
                    $month = 12;
                    $year = $year - 1;
                }
                else
                {
                    $month = $month - 1;
                }
                
                $n--;
            }
            
            $total_seconds = $midnight - ($tot * $oneday) + $day;
            
        }
        
        return $total_seconds;
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
				$sql .= " AND blog_name = '$blog_name'";
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
                $sql .= " WHERE blog_name = '$weblog'";
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
        
		$path	= (preg_match("#".LD."path=(.+?)".RD."#", $tmpl, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
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
					
					$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $path.'/C'.$row['cat_id'], $chunk))."\n"; 
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
                $sql .= " WHERE blog_name = '$weblog'";
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
		        
        
        $sql .= "AND exp_weblog_titles.entry_date < ".$LOC->now." ";
                   
        $sql .= "AND (exp_weblog_titles.expiration_date = 0 || exp_weblog_titles.expiration_date > ".$LOC->now.") ";
		        
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
		$c_path	= (preg_match("#".LD."path=(.+?)".RD."#", $cat_chunk, $match)) ? $match['1'] : 'SITE_INDEX';
		$cat_chunk = preg_replace("#".LD."path=.+?".RD."#", '{PATH}', $cat_chunk);	
        $c_pre 	= trim(preg_replace("/(.*?)".LD."category_name".RD.".*{END}/s", "\\1", $cat_chunk));
        $c_post	= trim(preg_replace("/.*?".LD."category_name".RD."(.*?){END}/s",   "\\1", $cat_chunk));	
        
        $tit_chunk = (preg_match("/".LD."entry_titles\s*".RD."(.*?)".LD.SLASH."entry_titles\s*".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : '';
        $tit_chunk .='{END}';
		$t_path	= (preg_match("#".LD."path=(.+?)".RD."#", $tit_chunk, $match)) ? $match['1'] : 'SITE_INDEX';
		$tit_chunk = preg_replace("#".LD."path=.+?".RD."#", '{PATH}', $tit_chunk);	
        $t_pre 	= trim(preg_replace("/(.*?)".LD."title".RD.".*{END}/s", "\\1", $tit_chunk));
        $t_post	= trim(preg_replace("/.*?".LD."title".RD."(.*?){END}/s",   "\\1", $tit_chunk));	
                
		$str = '';
				
		if ($TMPL->fetch_param('style') == '' OR $TMPL->fetch_param('style') == 'nested')
        {
        	if ($result->num_rows > 0)
        	{        		
        		$i = 0;	
				foreach($result->result as $row)
				{
					$chunk = "<li>".$t_pre.$row['title'].$t_post."</li>";
					$blog_array[$i.'_'.$row['cat_id']] = $FNS->remove_double_slashes(str_replace("{PATH}", $FNS->create_url($FNS->extract_path($t_path)).'/'.$row['entry_id'].'/', $chunk))."\n"; 
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
					
					$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $c_path.'/C'.$row['cat_id'], $chunk))."\n"; 
					
					foreach($result->result as $trow)
					{
						if ($trow['cat_id'] == $row['cat_id'])
						{
							$chunk = $t_pre.$trow['title'].$t_post;
							$str .= $FNS->remove_double_slashes(str_replace("{PATH}", $FNS->create_url($FNS->extract_path($t_path)).'/'.$trow['entry_id'].'/', $chunk))."\n"; 
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
            		foreach($blog_array as $k => $v)
            		{
            			$k = substr($k, strpos($k, '_') + 1);
            		
            			if ($key == $k)
            			{
            				$this->category_list[] = $v;
            			}
            		}
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
            	
            	if ($this->category_subtree($key) != 0)
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
            		foreach($blog_array as $k => $v)
            		{
            			$k = substr($k, strpos($k, '_') + 1);
            		
            			if ($key == $k)
            			{
            				$this->category_list[] = $v;
            			
            			}
            		}
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
									) != 0 )
				$t .= "$tab\t";
            	            	
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
        global $IN, $TMPL, $DB;

        if ( ! ereg("^C", $IN->QSTR))
        {
 			return;
 		}
        
		$cat_id = substr($IN->QSTR, 1);
		
		$query = $DB->query("SELECT cat_name, cat_image FROM exp_categories WHERE cat_id = '$cat_id'");
		
		if ($query->num_rows == 0)
		{
			return;
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
                        
        $sql = "SELECT t1.entry_id, t1.title, t1.url_title 
        		FROM exp_weblog_titles t1, exp_weblog_titles t2, exp_weblogs 
        		WHERE t1.weblog_id = exp_weblogs.weblog_id ";
        		
        if (is_numeric($IN->QSTR))
        {
			$sql .= " AND t1.entry_id != '$IN->QSTR' AND t2.entry_id  = '$IN->QSTR' ";
        }
        else
        {
			$sql .= " AND t1.url_title != '$IN->QSTR' AND t2.url_title  = '$IN->QSTR' ";
        }
        		
		$sql .= " AND t1.entry_date > t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$LOC->now.") ";
        		
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
        		
        $sql .= " ORDER BY t1.entry_date LIMIT 1";
                                
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
        	return;
        }
        
		$path = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		
		$path .= '/'.$query->row['url_title'].'/';
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("#".LD."title".RD."#", $query->row['title'], $TMPL->tagdata);	
				
        return $FNS->remove_double_slashes($TMPL->tagdata);
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
        
        $sql = "SELECT t1.entry_id, t1.title, t1.url_title
        		FROM exp_weblog_titles t1, exp_weblog_titles t2, exp_weblogs 
        		WHERE t1.weblog_id = exp_weblogs.weblog_id ";
        		
        if (is_numeric($IN->QSTR))
        {
			$sql .= " AND t1.entry_id != '$IN->QSTR' AND t2.entry_id  = '$IN->QSTR' ";
        }
        else
        {
			$sql .= " AND t1.url_title != '$IN->QSTR' AND t2.url_title  = '$IN->QSTR' ";
        }

		$sql .= " AND t1.entry_date < t2.entry_date AND (t1.expiration_date = 0 || t1.expiration_date > ".$LOC->now.") ";
        
        		
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
        		
        		
        $sql .= " ORDER BY t1.entry_date desc LIMIT 1";
                        
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
        	return;
        }
        
		$path = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		
		$path .= '/'.$query->row['url_title'].'/';
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("#".LD."title".RD."#", $query->row['title'], $TMPL->tagdata);
		
        return $FNS->remove_double_slashes($TMPL->tagdata);
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
        
        $sql = "SELECT DISTINCT month, year
                FROM exp_weblog_titles 
                WHERE (expiration_date = 0 || expiration_date > UNIX_TIMESTAMP()) ";
          
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
        
           
        $sql .= "ORDER BY entry_date desc ";   
                
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
