<?php

/*
=====================================================
 ExpressionEngine - by pMachine
-----------------------------------------------------
 http://www.pmachine.com/
-----------------------------------------------------
 Copyright (c) 2003 - 2006 pMachine, Inc.
=====================================================
 THIS IS COPYRIGHTED SOFTWARE
 PLEASE READ THE LICENSE AGREEMENT
 http://eedocs.pmachine.com/license.html
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

    var $limit	= '100';   // Default maximum query results if not specified.

  	// These variable are all set dynamically
  	
    var $query;
    var $TYPE;  
    var $entry_id				= '';
    var	$uri					= '';
    var $uristr					= '';
    var $return_data    		= '';     	// Final data 
    var $tb_action_id   		= '';
	var $basepath				= '';
	var $hit_tracking_id		= FALSE;
    var	$sql					= FALSE;
    var $display_tb_rdf			= FALSE;
    var $cfields        		= array();
    var $dfields				= array();
    var $rfields				= array();
    var $mfields        		= array();
    var $categories     		= array();
    var $weblog_name     		= array();
    var $weblogs_array			= array();
    var $related_entries		= array();
    var $reverse_related_entries= array();
    var $reserved_cat_segment 	= '';
	var $use_category_names		= FALSE;
	var $dynamic_sql			= FALSE;
	var $tb_captcha_hash		= '';
	var $cat_request			= FALSE;
    
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
	var $total_pages			= 1;
	var $multi_fields			= array();
	var $display_by				= '';
	var $total_rows				=  0;
	var $pager_sql				= '';
	var $p_limit				= '';
	var $p_page					= '';

	
	// SQL Caching
	
	var $sql_cache_dir			= 'sql_cache/';
	
	// Misc. - Class variable usable by extensions
	var $misc					= FALSE;

    // ----------------------------------------
    //  Constructor
    // ----------------------------------------

    function Weblog()
    { 
		global $PREFS;
				
		$this->p_limit = $this->limit;
		
		if ($PREFS->ini("use_category_name") == 'y' AND $PREFS->ini("reserved_category_word") != '')
		{
			$this->use_category_names	= $PREFS->ini("use_category_name");
			$this->reserved_cat_segment	= $PREFS->ini("reserved_category_word");
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
		
		if ($TMPL->fetch_param('dynamic_parameters') !== FALSE AND isset($_POST) AND count($_POST) > 0)
		{			
			foreach (explode('|', $TMPL->fetch_param('dynamic_parameters')) as $var)
			{			
				if (isset($_POST[$var]) AND in_array($var, array('weblog', 'entry_id', 'category', 'orderby', 'sort', 'sticky', 'show_future_entries', 'show_expired', 'entry_id_from', 'entry_id_to', 'not_entry_id', 'start_on', 'stop_before', 'year', 'month', 'day', 'display_by', 'limit', 'username', 'status', 'group_id', 'cat_limit', 'month_limit', 'offset')))
				{
					$tag .= $var.'="'.$_POST[$var].'"';
				}
			}
		}
    	
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
			
		if ( ! @is_dir($cache_dir))
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
        
        // If the "related_categories" mode is enabled
        // we'll call the "related_categories" function
        // and bail out.
        
        if ($TMPL->fetch_param('related_categories_mode') == 'on')
        {
        	return $this->related_entries();
        }
		// Onward...
                 
        $this->initialize();
        
		$this->uri = ($IN->QSTR != '') ? $IN->QSTR : 'index.php';

        $enable = array(
        					'categories' 	=> TRUE, 
        					'pagination' 	=> TRUE, 
        					'member_data'	=> TRUE, 
        					'custom_fields'	=> TRUE, 
        					'trackbacks'	=> TRUE
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
			elseif (isset($enable[$disable]))
			{
				$enable[$disable] = FALSE;
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
			else
			{
				if ($TMPL->fetch_param('dynamic') != 'off')
				{
					if (preg_match("#(^|\/)C(\d+)#", $IN->QSTR, $match) OR in_array($this->reserved_cat_segment, explode("/", $IN->QSTR)))
					{
						$this->cat_request = TRUE;
					}
				}
			}

			
			if (FALSE !== ($cache = $this->fetch_cache('pagination_count')))
			{
				if (FALSE !== ($this->fetch_cache('field_pagination')))
				{
					if (FALSE !== ($pg_query = $this->fetch_cache('pagination_query')))
					{
						$this->paginate = TRUE;
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
        
        $this->track_views();
                        
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
		
		// Does the tag contain "related entries" that we need to parse out?
		
		if (count($TMPL->related_data) > 0 AND count($this->related_entries) > 0)
		{
			$this->parse_related_entries();
		}
		
		if (count($TMPL->reverse_related_data) > 0 AND count($this->reverse_related_entries) > 0)
		{
			$this->parse_reverse_related_entries();
		}
                                
        return $this->return_data;        
    }
    // END


    // ----------------------------------------
    //  Process related entries
    // ----------------------------------------

	function parse_related_entries()
	{
		global $TMPL, $DB, $REGX, $FNS;
		
		$sql = "SELECT * FROM exp_relationships WHERE rel_id IN (";
		
		$templates = array();
		foreach ($this->related_entries as $val)
		{ 
			$x = explode('_', $val);
			$sql .= "'".$x['0']."',";
			$templates[] = array($x['0'], $x['1'], $TMPL->related_data[$x['1']]);
		}
				
		$sql = substr($sql, 0, -1).')';
		$query = $DB->query($sql);
		
		if ($query->num_rows == 0)
			return;
		
		/* --------------------------------
		/*  Without this the Related Entries were inheriting the parameters of
		/*  the enclosing Weblog Entries tag.  Sometime in the future we will
		/*  likely allow Related Entries to have their own parameters
		/* --------------------------------*/ 
		
		$TMPL->tagparams = array('rdf'=> "off");
			
		$return_data = $this->return_data;
	
		foreach ($templates as $temp)
		{
			foreach ($query->result as $row)
			{
				if ($row['rel_id'] != $temp['0'])
					continue;
				
				/* --------------------------------------
				/*  If the data is emptied (cache cleared), then we 
				/*  rebuild it with fresh data so processing can continue.
				/* --------------------------------------*/
				
				if (trim($row['rel_data']) == '')
				{
					$rewrite = array(
									 'type'			=> $row['rel_type'],
									 'parent_id'	=> $row['rel_parent_id'],
									 'child_id'		=> $row['rel_child_id'],
									 'related_id'	=> $row['rel_id']
								);
		
					$FNS->compile_relationship($rewrite, FALSE);
					
					$results = $DB->query("SELECT rel_data FROM exp_relationships WHERE rel_id = '".$row['rel_id']."'");
					$row['rel_data'] = $results->row['rel_data'];
				}
				
				/* --------------------------------------
				/*  Begin Processing
				/* --------------------------------------*/
				
				$this->initialize();
				
				if ($reldata = @unserialize($row['rel_data']))
				{
					$TMPL->var_single	= $temp['2']['var_single'];
					$TMPL->var_pair		= $temp['2']['var_pair'];
					$TMPL->var_cond		= $temp['2']['var_cond'];
					$TMPL->tagdata		= $temp['2']['tagdata'];
										
					if ($row['rel_type'] == 'blog')
					{
						// Bug fix for when categories were not being inserted
						// correctly for related weblog entries.  Bummer.
						
						if (sizeof($reldata['categories'] == 0) && ! isset($reldata['cats_fixed']))
						{
							$fixdata = array(
											'type'			=> $row['rel_type'],
											'parent_id'		=> $row['rel_parent_id'],
											'child_id'		=> $row['rel_child_id'],
											'related_id'	=> $row['rel_id']
										);
						
							$FNS->compile_relationship($fixdata, FALSE);
							$reldata['categories'] = $FNS->cat_array;
						}
					
						$this->query = $reldata['query'];
						$this->categories = array($this->query->row['entry_id'] => $reldata['categories']);
						$this->parse_weblog_entries();
						
						$marker = LD."REL[".$row['rel_id']."][".$temp['2']['field_name']."]".$temp['1']."REL".RD;
						$return_data = str_replace($marker, $this->return_data, $return_data);					
					}
					elseif ($row['rel_type'] == 'gallery')
					{									
						if ( ! class_exists('Gallery'))
						{
							include_once PATH_MOD.'gallery/mod.gallery'.EXT;
						}
						
						$GAL = new Gallery;
						$GAL->one_entry = TRUE;
						$GAL->query = $reldata['query'];
						$GAL->TYPE = $this->TYPE;
						$GAL->parse_gallery_entries();

						$marker = LD."REL[".$row['rel_id']."][".$temp['2']['field_name']."]".$temp['1']."REL".RD;
						$return_data = str_replace($marker, $GAL->return_data, $return_data);
					}
				}
			}
		}
		
		$this->return_data = $return_data;
	}
	// END
	
	
	// ----------------------------------------
    //  Process reverse related entries
    // ----------------------------------------

	function parse_reverse_related_entries()
	{
		global $TMPL, $DB, $REGX, $FNS;
		
		$sql = "SELECT * FROM exp_relationships 
				WHERE rel_child_id IN ('".implode("','", array_keys($this->reverse_related_entries))."')";

		$query = $DB->query($sql);
		
		if ($query->num_rows == 0)
		{
			$this->return_data = preg_replace("|".LD."REV_REL\[.*?\]\[[0-9]+\]REV_REL".RD."|", '', $this->return_data);
			return;
		}
		
		/* --------------------------------
		/*  Data Processing Time
		/* --------------------------------*/ 
		
		$entry_data = array();
		
		foreach($query->result as $row)
		{	
			/* --------------------------------------
			/*  If the data is emptied (cache cleared or first process), then we 
			/*  rebuild it with fresh data so processing can continue.
			/* --------------------------------------*/
			
			if (trim($row['reverse_rel_data']) == '')
			{
				$rewrite = array(
								 'type'			=> $row['rel_type'],
								 'parent_id'	=> $row['rel_parent_id'],
								 'child_id'		=> $row['rel_child_id'],
								 'related_id'	=> $row['rel_id']
							);
	
				$FNS->compile_relationship($rewrite, FALSE, TRUE);
				
				$results = $DB->query("SELECT reverse_rel_data FROM exp_relationships WHERE rel_parent_id = '".$row['rel_parent_id']."'");
				$row['reverse_rel_data'] = $results->row['reverse_rel_data'];
			}
			
			/* --------------------------------------
			/*  Unserialize the entries data, please
			/* --------------------------------------*/
			
			if ($revreldata = @unserialize($row['reverse_rel_data']))
			{
				$entry_data[$row['rel_child_id']][$row['rel_parent_id']] = $revreldata;
			}
		}
		
		/* --------------------------------
		/*  Without this the Reverse Related Entries were inheriting the parameters of
		/*  the enclosing Weblog Entries tag, which is not appropriate.
		/* --------------------------------*/ 
		
		$TMPL->tagparams = array('rdf'=> "off");
			
		$return_data = $this->return_data;
		
		foreach ($this->reverse_related_entries as $entry_id => $templates)
		{
			/* --------------------------------------
			/*  No Entries?  Remove Reverse Related Tags and Continue to Next Entry
			/* --------------------------------------*/
			
			if ( ! isset($entry_data[$entry_id]))
			{
				foreach($templates as $tkey => $template)
				{
					$return_data = str_replace(LD."REV_REL[".$TMPL->reverse_related_data[$template]['marker']."][".$entry_id."]REV_REL".RD, '', $return_data);		
					continue(2);
				}
			}
			
			/* --------------------------------------
			/*  Process Our Reverse Related Templates
			/* --------------------------------------*/
			
			foreach($templates as $tkey => $template)
			{	
				$i = 0;
				$cats = array();
				
				$params = $TMPL->reverse_related_data[$template]['params'];
				
				if ( ! is_array($params))
				{
					$params = array('open');
				}
				elseif( ! isset($params['status']))
				{
					$params['status'] = 'open';
				}
				
				/* --------------------------------------
				/*  Entries have to be ordered, sorted and other stuff
				/* --------------------------------------*/
				
				$new	= array();
				$order	= ( ! isset($params['orderby'])) ? 'date' : $params['orderby'];
				$offset	= ( ! isset($params['offset']) OR ! is_numeric($params['offset'])) ? 0 : $params['offset'];
				$limit	= ( ! isset($params['limit']) OR ! is_numeric($params['limit'])) ? 100 : $params['limit'];
				$sort	= ( ! isset($params['sort']))	 ? 'asc' : $params['sort'];
				$random = ($order == 'random') ? TRUE : FALSE;
				
				$base_orders = array('random', 'date', 'title', 'url_title', 'edit_date', 'comment_total', 'username', 'screen_name', 'most_recent_comment', 'expiration_date',
									 'view_count_one', 'view_count_two', 'view_count_three', 'view_count_four');
	
				if ( ! in_array($order, $base_orders))
				{
					if ( isset($this->cfields[$order]))
					{
						$order = 'field_id_'.$this->cfields[$order];
					}
					else
					{
						$order = 'date';
					}
				}
				
				if ($order == 'date' OR $order == 'random') 
				{
					$order = 'entry_date';
				}
				
				if (isset($params['weblog']) && trim($params['weblog']) != '')
				{
					if (sizeof($this->weblogs_array) == 0)
					{
						$results = $DB->query("SELECT weblog_id, blog_name FROM exp_weblogs WHERE is_user_blog = 'n'");
						
						foreach($results->result as $row)
						{
							$this->weblogs_array[$row['blog_name']] = $row['weblog_id'];
						}
					}
					
					$weblogs = explode('|', trim($params['weblog']));
					
					if (substr($weblogs['0'], 0, 3) == 'not ')
					{
						$weblogs['0'] = trim(substr($weblogs['0'], 3));
						$allowed	  = $this->weblogs_array;
						
						foreach($weblogs as $name)
						{
							unset($allowed[$name]);
						}
					}
					else
					{
						foreach($weblogs as $name)
						{
							if (isset($this->weblogs_array[$name]))
							{
								$allowed[$name] = $this->weblogs_array[$name];
							}
						}
					}
				}
				
				if (isset($params['status']) && trim($params['status']) != '')
				{	
					$stati	= explode('|', trim($params['status']));
					$status_state = 'positive';
					
					if (substr($stati['0'], 0, 4) == 'not ')
					{
						$stati['0'] = trim(substr($stati['0'], 3));
						$status_state = 'negative';
						$stati[] = 'closed';
					}
					elseif ( ! in_array('open', $stati))
					{
						$stati[] = 'open';
					}
				}
				
				
				foreach($entry_data[$entry_id] as $relating_data)
				{
					if ( ! isset($params['weblog']) OR in_array($relating_data['query']->row['weblog_id'], $allowed))
					{
						if (isset($stati) && isset($relating_data['query']->row[$order]))
						{
							if ($status_state == 'negative' && ! in_array($relating_data['query']->row['status'], $stati))
							{
								$new[$relating_data['query']->row[$order]] = $relating_data;
							}
							elseif($status_state == 'positive' && in_array($relating_data['query']->row['status'], $stati))
							{
								$new[$relating_data['query']->row[$order]] = $relating_data;
							}
						}
						elseif ($relating_data['query']->row['status'] != 'closed')
						{
							$new[$relating_data['query']->row[$order]] = $relating_data;
						}
					}
				}
				
				if ($random === TRUE)
				{
					shuffle($new);
				}
				elseif ($sort == 'desc')
				{
					ksort($new);
				}
				else
				{
					krsort($new);
				}
				
				$output_data[$entry_id] = array_slice($new, $offset, $limit);
				
				if (sizeof($output_data[$entry_id]) == 0)
				{
					$return_data = str_replace(LD."REV_REL[".$TMPL->reverse_related_data[$template]['marker']."][".$entry_id."]REV_REL".RD, '', $return_data);
					continue;
				}
				
				/* --------------------------------------
				/*  Finally!  We get to process our parents
				/* --------------------------------------*/
				
				foreach($output_data[$entry_id] as $relating_data)
				{
					if ($i == 0)
					{
						$query = $relating_data['query']; 
					}
					else
					{
						$query->result[] = $relating_data['query']->row;
					}
					
					$cats[$relating_data['query']->row['entry_id']] = $relating_data['categories'];
					
					++$i;
				}
				
				$query->num_rows = $i;
			
				$this->initialize();
				
				$TMPL->var_single	= $TMPL->reverse_related_data[$template]['var_single'];
				$TMPL->var_pair		= $TMPL->reverse_related_data[$template]['var_pair'];
				$TMPL->var_cond		= $TMPL->reverse_related_data[$template]['var_cond'];
				$TMPL->tagdata		= $TMPL->reverse_related_data[$template]['tagdata'];
									
				$this->query = $query;
				$this->categories = $cats;
				$this->parse_weblog_entries();
				
				$return_data = str_replace(	LD."REV_REL[".$TMPL->reverse_related_data[$template]['marker']."][".$entry_id."]REV_REL".RD, 
											$this->return_data, 
											$return_data);		
			}
		}
		
		$this->return_data = $return_data;
	}
	// END


    // ----------------------------------------
    //  Track Views
    // ----------------------------------------

	function track_views()
	{
		global $DB, $TMPL;
	
		if ( ! $TMPL->fetch_param('track_views') OR $this->hit_tracking_id === FALSE OR ! in_array($TMPL->fetch_param('track_views'), array("one", "two", "three", "four")))
		{
			return;
		}
		
		if ($this->field_pagination == TRUE AND $this->p_page > 0)
		{
			return;
		}
	
		$column = "view_count_".$TMPL->fetch_param('track_views');
				
		$sql = "UPDATE exp_weblog_titles SET {$column} = ({$column} + 1) WHERE ";
		
		$sql .= (is_numeric($this->hit_tracking_id)) ? "entry_id = {$this->hit_tracking_id}" : "url_title = '".$DB->escape_str($this->hit_tracking_id)."'";
					
		$DB->query($sql);
	}
	// END


    // ----------------------------------------
    //  Fetch pagination data
    // ----------------------------------------

    function fetch_pagination_data()
    {
		global $TMPL, $FNS, $EXT;
				
		if (preg_match("/".LD."paginate".RD."(.+?)".LD.SLASH."paginate".RD."/s", $TMPL->tagdata, $match))
		{ 
			if ($TMPL->fetch_param('paginate_type') == 'field')
			{ 
				if (preg_match("/".LD."multi_field\=[\"'](.+?)[\"']".RD."/s", $TMPL->tagdata, $mmatch))
				{
					$this->multi_fields = $FNS->fetch_simple_conditions($mmatch['1']);
					$this->field_pagination = TRUE;
				}
			}
			
			// -------------------------------------------
			// 'weblog_module_fetch_pagination_data' hook.
			//  - Works with the 'weblog_module_create_pagination' hook
			//  - Developers, if you want to modify the $this object remember
			//    to use a reference on function call.
			//
				if (isset($EXT->extensions['weblog_module_fetch_pagination_data']))
				{
					$edata = $EXT->call_extension('weblog_module_fetch_pagination_data', $this);
					if ($EXT->end_script === TRUE) return;
				}
			//
			// -------------------------------------------
			
			$this->paginate	= TRUE;
			$this->paginate_data = $match['1'];
						
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

		if ($this->pagination_links == '')
		{
		//	return;
		}
		
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
        
        $sql = "SELECT field_id, field_type, field_name 
        		FROM exp_weblog_fields, exp_weblogs
        		WHERE exp_weblogs.field_group = exp_weblog_fields.group_id";
        
        if (USER_BLOG !== FALSE)
        {
            $sql .= " AND group_id = '".$DB->escape_str(UB_FIELD_GRP)."'";
        }
        else
        {
        	$sql .= " AND exp_weblogs.is_user_blog = 'n'";
        }
                
        $query = $DB->query($sql);
                
        foreach ($query->result as $row)
        {
        	// Assign date fields
        	if ($row['field_type'] == 'date')
        	{
				$this->dfields[$row['field_name']] = $row['field_id'];
        	}
			// Assign relationship fields
        	if ($row['field_type'] == 'rel')
        	{
				$this->rfields[$row['field_name']] = $row['field_id'];
        	}
        	
        	// Assign standard fields
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
        
        $query = $DB->query("SELECT m_field_id, m_field_name, m_field_fmt FROM exp_member_fields");
                
        foreach ($query->result as $row)
        { 
            $this->mfields[$row['m_field_name']] = array($row['m_field_id'], $row['m_field_fmt']);
        }
    }
    // END


    // ----------------------------------------
    //  Fetch categories
    // ----------------------------------------

    function fetch_categories()
    {
        global $DB;
        
        $sql = "SELECT c.cat_name, c.cat_id, c.cat_image, c.cat_description, c.parent_id,
						p.cat_id, p.entry_id, c.group_id 
				FROM	exp_categories AS c, exp_category_posts AS p
				WHERE	c.cat_id = p.cat_id
				AND		p.entry_id IN (";
                
        $categories = array();
                
        foreach ($this->query->result as $row)
        {
            $sql .= "'".$row['entry_id']."',"; 
            
            $categories[] = $row['entry_id'];
        }
        
        $sql = substr($sql, 0, -1).')';
        
        $sql .= " ORDER BY c.group_id, c.parent_id, c.cat_order";
        
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
            return;
        }
        
        foreach ($categories as $val)
        {
            $this->temp_array = array();
            $this->cat_array  = array();
            $parents = array();
        
            foreach ($query->result as $row)
            {    
                if ($val == $row['entry_id'])
                {
                    $this->temp_array[$row['cat_id']] = array($row['cat_id'], $row['parent_id'], $row['cat_name'], $row['cat_image'], $row['cat_description'], $row['group_id']);
                    
                    if ($row['parent_id'] > 0 && ! isset($this->temp_array[$row['parent_id']])) $parents[$row['parent_id']] = '';
                    unset($parents[$row['cat_id']]);
                }              
            }
            
            if (count($this->temp_array) == 0)
            {
                $temp = FALSE;
            }
            else
            {
            	foreach($this->temp_array as $k => $v) 
				{			
					if (isset($parents[$v['1']])) $v['1'] = 0;
				
					if (0 == $v['1'])
					{    
						$this->cat_array[] = $v;
						$this->process_subcategories($k);
					}
				}
			}
			
			$this->categories[$val] = $this->cat_array;
        }        
        
        unset($this->temp_array);
        unset($this->cat_array);
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
        $corder			= array();
		$offset			=  0;
		$page_marker	= FALSE;
        $dynamic		= TRUE;
        
        $this->dynamic_sql = TRUE;
                 
        // ----------------------------------------------
        //  Is dynamic='off' set?
        // ----------------------------------------------
        
        // If so, we'll override all dynamically set variables
        
		if ($TMPL->fetch_param('dynamic') == 'off')
		{		
			$dynamic = FALSE;
		}  
		
        // ----------------------------------------------
        //  Do we allow dynamic POST variables to set parameters?
        // ----------------------------------------------

		if ($TMPL->fetch_param('dynamic_parameters') !== FALSE AND isset($_POST) AND count($_POST) > 0)
		{			
			foreach (explode('|', $TMPL->fetch_param('dynamic_parameters')) as $var)
			{			
				if (isset($_POST[$var]) AND in_array($var, array('weblog', 'entry_id', 'category', 'orderby', 'sort', 'sticky', 'show_future_entries', 'show_expired', 'entry_id_from', 'entry_id_to', 'not_entry_id', 'start_on', 'stop_before', 'year', 'month', 'day', 'display_by', 'limit', 'username', 'status', 'group_id', 'cat_limit', 'month_limit', 'offset')))
				{
					$TMPL->tagparams[$var] = $_POST[$var];
				}
			}
		}		
		
        // ----------------------------------------------
        //  Parse the URL query string
        // ----------------------------------------------
        
        $this->uristr = $IN->URI;

        if ($qstring == '')
			$qstring = $IN->QSTR;
			
		$this->basepath = $FNS->create_url($this->uristr, 1);
		
		if ($qstring == '')
		{
			if ($TMPL->fetch_param('require_entry') == 'yes')
			{
				return $TMPL->no_results();
			}
		}
		else
		{
			// --------------------------------------
			//  Do we have a pure ID number?
			// --------------------------------------
		
			if (is_numeric($qstring) AND $dynamic)
			{
				$entry_id = $qstring;
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
				
				// added (^|\/) to make sure this doesn't trigger with url titles like big_party_2006
				if (preg_match("#(^|\/)(\d{4}/\d{2})#", $qstring, $match) AND $dynamic)
				{		
					$ex = explode('/', $match['2']);
					
					$year	= $ex['0'];
					$month	= $ex['1'];


					$qstring = $REGX->trim_slashes(str_replace($match['2'], '', $qstring));

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
				
				if (in_array($this->reserved_cat_segment, explode("/", $qstring)) AND $dynamic AND $TMPL->fetch_param('weblog'))
				{
					$qstring = preg_replace("/(.*?)".preg_quote($this->reserved_cat_segment)."\//i", '', $qstring);
						
					$sql = "SELECT DISTINCT cat_group FROM exp_weblogs WHERE ";
					
					if (USER_BLOG !== FALSE)
					{
						$sql .= " weblog_id='".UB_BLOG_ID."'";
					}
					else
					{
						$xsql = $FNS->sql_andor_string($TMPL->fetch_param('weblog'), 'blog_name');
						
						if (substr($xsql, 0, 3) == 'AND') $xsql = substr($xsql, 3);
						
						$sql .= ' '.$xsql;
					}
						
					$query = $DB->query($sql);
					
					if ($query->num_rows == 1)
					{
						$result = $DB->query("SELECT cat_id FROM exp_categories 
											  WHERE cat_name='".$DB->escape_str($qstring)."' 
											  AND group_id IN ('".str_replace('|', "','", $DB->escape_str($query->row['cat_group']))."')");
					
						if ($result->num_rows == 1)
						{
							$qstring = 'C'.$result->row['cat_id'];
						}
						else
						{
							/*
							$result = $DB->query("SELECT cat_id FROM exp_categories WHERE cat_name='".$DB->escape_str($qstring)."'");
							
							if ($result->num_rows == 1)
							{
								$qstring = 'C'.$result->row['cat_id'];
							}
							*/
						}
					}
				}

				// Numeric version of the category

				if (preg_match("#(^|\/)C(\d+)#", $qstring, $match) AND $dynamic)
				{		
					$this->cat_request = TRUE;
					
					$cat_id = $match['2'];	
														
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
					if (strstr($qstring, '/'))
					{
						$xe = explode('/', $qstring);
						$qstring = current($xe);
					}
					
					if ($dynamic == TRUE)
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
						
						if ($query->row['count'] == 0)
						{
							if ($TMPL->fetch_param('require_entry') == 'yes')
							{
								return $TMPL->no_results();
							}
						
							$qtitle = '';
						}
						else
						{
							$qtitle = $qstring;
						}
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
		
		$order  = $TMPL->fetch_param('orderby');
		$sort   = $TMPL->fetch_param('sort');
		$sticky = $TMPL->fetch_param('sticky');
		
		/* -------------------------------------
		/*  Multiple Orders and Sorts...
		/* -------------------------------------*/
		
		if ($order !== FALSE && stristr($order, '|'))
		{
			$order_array = explode('|', $order);
			
			if ($order_array['0'] == 'random')
			{
				$order_array = array('random');
			}
		}
		else
		{
			$order_array = array($order);
		}
		
		if ($sort !== FALSE && stristr($sort, '|'))
		{
			$sort_array = explode('|', $sort);
		}
		else
		{
			$sort_array = array($sort);
		}
		
		/* -------------------------------------
		/*  Validate Results for Later Processing
		/* -------------------------------------*/
					
		$base_orders = array('random', 'date', 'title', 'url_title', 'edit_date', 'comment_total', 'username', 'screen_name', 'most_recent_comment', 'expiration_date',
							 'view_count_one', 'view_count_two', 'view_count_three', 'view_count_four');
		
		foreach($order_array as $key => $order)
		{
			if ( ! in_array($order, $base_orders))
			{	
				if (FALSE !== $order)
				{
					if (isset($this->cfields[$order]))
					{
						$corder[$key] = $this->cfields[$order];
						$order_array[$key] = 'custom_field';
					}
					else
					{
						$order_array[$key] = FALSE;
					}
				}
			}
			
			if ( ! isset($sort_array[$key]))
			{
				$sort_array[$key] = 'desc';
			}
		}
		
		foreach($sort_array as $key => $sort)
		{
			if ($sort == FALSE || ($sort != 'asc' AND $sort != 'desc'))
			{
				$sort_array[$key] = "desc";
			}
		}
		
        // ----------------------------------------------
        //  Build the master SQL query
        // ----------------------------------------------
		
		$sql_a = "SELECT ";
		
		$sql_b = ($TMPL->fetch_param('category') || $TMPL->fetch_param('category_group') || $cat_id != '' || $order_array['0'] == 'random') ? "DISTINCT(t.entry_id) " : "t.entry_id ";
				
		if ($this->field_pagination == TRUE)
		{
			$sql_b .= ",wd.* ";
		}

		$sql_c = "COUNT(t.entry_id) AS count ";
		
		$sql = "FROM exp_weblog_titles AS t
				LEFT JOIN exp_weblogs ON t.weblog_id = exp_weblogs.weblog_id ";
				
		if ($this->field_pagination == TRUE)
		{
			$sql .= "LEFT JOIN exp_weblog_data AS wd ON t.entry_id = wd.entry_id ";
		}
						
		if (in_array('custom_field', $order_array))
		{
			$sql .= "LEFT JOIN exp_weblog_data AS wd ON t.entry_id = wd.entry_id ";
		}
		
		$sql .= "LEFT JOIN exp_members AS m ON m.member_id = t.author_id ";
				
						  
        if ($TMPL->fetch_param('category') || $TMPL->fetch_param('category_group') || $cat_id != '')                      
        {
        	/* --------------------------------
        	/*  We use LEFT JOIN when there is a 'not' so that we get 
        	/*  entries that are not assigned to a category.
        	/* --------------------------------*/
        	
        	if ((substr($TMPL->fetch_param('category_group'), 0, 3) == 'not' OR substr($TMPL->fetch_param('category'), 0, 3) == 'not') && $TMPL->fetch_param('uncategorized_entries') !== 'n')
        	{
        		$sql .= "LEFT JOIN exp_category_posts ON t.entry_id = exp_category_posts.entry_id
						 LEFT JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
        	}
        	else
        	{
        		$sql .= "INNER JOIN exp_category_posts ON t.entry_id = exp_category_posts.entry_id
						 INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
			}
        }
        
        $sql .= "WHERE t.entry_id !='' ";

        // ----------------------------------------------
        // We only select entries that have not expired 
        // ----------------------------------------------
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		        
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
			$sql .= " AND t.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {        
			$sql .= " AND (t.expiration_date = 0 || t.expiration_date > ".$timestamp.") ";
        }
        
        // ----------------------------------------------
        //  Limit query by post ID for individual entries
        // ----------------------------------------------
         
        if ($entry_id != '')
        {           
        	$sql .= $FNS->sql_andor_string($entry_id, 't.entry_id').' ';
        }
        
        // ----------------------------------------------
        //  Limit query by post url_title for individual entries
        // ----------------------------------------------
         
        if ($url_title = $TMPL->fetch_param('url_title'))
        {           
        	$sql .= $FNS->sql_andor_string($url_title, 't.url_title').' ';
        }
        
        // ----------------------------------------------
        //  Limit query by entry_id range
        // ----------------------------------------------
                
        if ($entry_id_from = $TMPL->fetch_param('entry_id_from'))
        {
            $sql .= "AND t.entry_id >= '$entry_id_from' ";
        }
        
        if ($entry_id_to = $TMPL->fetch_param('entry_id_to'))
        {
            $sql .= "AND t.entry_id <= '$entry_id_to' ";
        }
                
        // ----------------------------------------------
        //  Exclude an individual entry
        // ----------------------------------------------

		if ($not_entry_id = $TMPL->fetch_param('not_entry_id'))
		{
			$sql .= ( ! is_numeric($not_entry_id)) 
					? "AND t.url_title != '{$not_entry_id}' " 
					: "AND t.entry_id  != '{$not_entry_id}' ";
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
                        $sql .= "AND t.weblog_id = '".$query->row['weblog_id']."' ";
                    }
                    else
                    {
                        $sql .= "AND (";
                        
                        foreach ($query->result as $row)
                        {
                            $sql .= "t.weblog_id = '".$row['weblog_id']."' OR ";
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
            $sql .= "AND t.entry_date >= '".$LOC->convert_human_date_to_gmt($TMPL->fetch_param('start_on'))."' ";

        if ($TMPL->fetch_param('stop_before'))
            $sql .= "AND t.entry_date < '".$LOC->convert_human_date_to_gmt($TMPL->fetch_param('stop_before'))."' ";
                

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
			
			$sql .= " AND t.entry_date >= ".$stime." AND t.entry_date <= ".$etime." ";            
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
				
// BEGIN_THT, Vinay 1/19/05
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
// END_THT: DST fixes
				
        		$sql .= " AND t.entry_date >= ".$stime." AND t.entry_date <= ".$etime." ";
            }
            else
            {
				$this->display_by = $TMPL->fetch_param('display_by');

                $lim = ( ! $TMPL->fetch_param('limit')) ? '1' : $TMPL->fetch_param('limit');
                 
                // -------------------------------------------
                //  If display_by = "month"
                // -------------------------------------------                          
                 
                if ($this->display_by == 'month')
                {   
					// We need to run a query and fetch the distinct months in which there are entries
				
					$dql = "SELECT t.year, t.month ".$sql;
					
					// ----------------------------------------------
					//  Add status declaration
					// ----------------------------------------------
									
					if ($status = $TMPL->fetch_param('status'))
					{
						$status = str_replace('Open',   'open',   $status);
						$status = str_replace('Closed', 'closed', $status);
						
						$sstr = $FNS->sql_andor_string($status, 't.status');
						
						if ( ! eregi("'closed'", $sstr))
						{
							$sstr .= " AND t.status != 'closed' ";
						}
						
						$dql .= $sstr;
					}
					else
					{
						$dql .= "AND t.status = 'open' ";
					}
				
					$query = $DB->query($dql);
				
					$distinct = array();
                	
					if ($query->num_rows > 0)
					{
						foreach ($query->result as $row)
						{ 
							$distinct[] = $row['year'].$row['month'];
						}
						
						$distinct = array_unique($distinct);
						
						sort($distinct);
						
						if ($sort_array['0'] == 'desc')
						{
							$distinct = array_reverse($distinct);
						}
						
						$this->total_rows = count($distinct);
						
						$cur = ($this->p_page == '') ? 0 : $this->p_page;
						
						$distinct = array_slice($distinct, $cur, $lim);	
						
						if ($distinct != FALSE)
						{
							$sql .= "AND (";
							
							foreach ($distinct as $val)
							{                	
								$sql .= "(t.year  = '".substr($val, 0, 4)."' AND t.month = '".substr($val, 4, 2)."') OR";
							}
							
							$sql = substr($sql, 0, -2).')';
						}
                	}                    
                }
                
                
                // -------------------------------------------
                //  If display_by = "day"
                // -------------------------------------------                          
                
                elseif ($this->display_by == 'day')
                {   
					// We need to run a query and fetch the distinct days in which there are entries
					
					$dql = "SELECT t.year, t.month, t.day ".$sql;
					
					// ----------------------------------------------
					//  Add status declaration
					// ----------------------------------------------
									
					if ($status = $TMPL->fetch_param('status'))
					{
						$status = str_replace('Open',   'open',   $status);
						$status = str_replace('Closed', 'closed', $status);
						
						$sstr = $FNS->sql_andor_string($status, 't.status');
						
						if ( ! eregi("'closed'", $sstr))
						{
							$sstr .= " AND t.status != 'closed' ";
						}
						
						$dql .= $sstr;
					}
					else
					{
						$dql .= "AND t.status = 'open' ";
					}
					
					$query = $DB->query($dql);
				
					$distinct = array();
                	
					if ($query->num_rows > 0)
					{
						foreach ($query->result as $row)
						{ 
							$distinct[] = $row['year'].$row['month'].$row['day'];
						}
						
						$distinct = array_unique($distinct);
						sort($distinct);
						
						if ($sort_array['0'] == 'desc')
						{
							$distinct = array_reverse($distinct);
						}
						
						$this->total_rows = count($distinct);
						
						$cur = ($this->p_page == '') ? 0 : $this->p_page;
						
						$distinct = array_slice($distinct, $cur, $lim);	
				
						if ($distinct != FALSE)
						{
							$sql .= "AND (";
							
							foreach ($distinct as $val)
							{                	
								$sql .= "(t.year  = '".substr($val, 0, 4)."' AND t.month = '".substr($val, 4, 2)."' AND t.day   = '".substr($val, 6)."' ) OR";
							}
							
							$sql = substr($sql, 0, -2).')';
                		}
                	}                    
                }
            }
        }
        
        
        // ----------------------------------------------
        //  Limit query "URL title"
        // ----------------------------------------------
         
        if ($qtitle != '' AND $dynamic)
        {    
			$sql .= "AND t.url_title = '".$DB->escape_str($qtitle)."' ";
			
			// We use this with hit tracking....
			
			$this->hit_tracking_id = $qtitle;
        }
                

		// We set a global variable which we use with entry hit tracking
		
		if ($entry_id != '' AND $this->entry_id !== FALSE)
		{
			$this->hit_tracking_id = $entry_id;
		}
        
        // ----------------------------------------------
        //  Limit query by category
        // ----------------------------------------------
                
        if ($TMPL->fetch_param('category'))
        {
        	if (stristr($TMPL->fetch_param('category'), '&'))
        	{
        		/* --------------------------------------
        		/*  First, we find all entries with these categories
        		/* --------------------------------------*/
        		
        		$for_sql = (substr($TMPL->fetch_param('category'), 0, 3) == 'not') ? trim(substr($TMPL->fetch_param('category'), 3)) : $TMPL->fetch_param('category');
        		
        		$csql = "SELECT exp_category_posts.entry_id, exp_category_posts.cat_id ".
						$sql.
						$FNS->sql_andor_string(str_replace('&', '|', $for_sql), 'exp_categories.cat_id');
        	
        		//exit($csql);
        	
        		$results = $DB->query($csql); 
        							  
        		if ($results->num_rows == 0)
        		{
					return;
        		}
        		
        		$type = 'IN';
        		$categories	 = explode('&', $TMPL->fetch_param('category'));
        		$entry_array = array();
        		
        		if (substr($categories['0'], 0, 3) == 'not')
        		{
        			$type = 'NOT IN';
        			
        			$categories['0'] = trim(substr($categories['0'], 3));
        		}
        		
        		foreach($results->result as $row)
        		{
        			$entry_array[$row['cat_id']][] = $row['entry_id'];
        		}
        		
        		if (sizeof($entry_array) < 2)
        		{
					return;
        		}
        		
        		$chosen = call_user_func_array('array_intersect', $entry_array);
        		
        		if (sizeof($chosen) == 0)
        		{
					return;
        		}
        		
        		$sql .= "AND t.entry_id ".$type." ('".implode("','", $chosen)."') ";
        	}
        	else
        	{
        		if (substr($TMPL->fetch_param('category'), 0, 3) == 'not' && $TMPL->fetch_param('uncategorized_entries') !== 'n')
        		{
        			$sql .= $FNS->sql_andor_string($TMPL->fetch_param('category'), 'exp_categories.cat_id', '', TRUE)." ";
        		}
        		else
        		{
        			$sql .= $FNS->sql_andor_string($TMPL->fetch_param('category'), 'exp_categories.cat_id')." ";
        		}
        	}
        }
        
        if ($TMPL->fetch_param('category_group'))
        {
            if (substr($TMPL->fetch_param('category_group'), 0, 3) == 'not' && $TMPL->fetch_param('uncategorized_entries') !== 'n')
			{
				$sql .= $FNS->sql_andor_string($TMPL->fetch_param('category_group'), 'exp_categories.group_id', '', TRUE)." ";
			}
			else
			{
				$sql .= $FNS->sql_andor_string($TMPL->fetch_param('category_group'), 'exp_categories.group_id')." ";
			}
        }
        
        if ($TMPL->fetch_param('category') === FALSE && $TMPL->fetch_param('category_group') === FALSE)
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
                $sql .=  "AND m.member_id = '".$SESS->userdata('member_id')."' ";
            }
            elseif ($username == 'NOT_CURRENT_USER')
            {
                $sql .=  "AND m.member_id != '".$SESS->userdata('member_id')."' ";
            }
            else
            {                
                $sql .= $FNS->sql_andor_string($username, 'm.username');
            }
        }
    
        // ----------------------------------------------
        // Add status declaration
        // ----------------------------------------------
                        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
			
			$sstr = $FNS->sql_andor_string($status, 't.status');
			
			if ( ! eregi("'closed'", $sstr))
			{
				$sstr .= " AND t.status != 'closed' ";
			}
			
			$sql .= $sstr;
        }
        else
        {
            $sql .= "AND t.status = 'open' ";
        }
            
        // ----------------------------------------------
        //  Add Group ID clause
        // ----------------------------------------------
        
        if ($group_id = $TMPL->fetch_param('group_id'))
        {
            $sql .= $FNS->sql_andor_string($group_id, 'm.group_id');
        }
              
        // --------------------------------------------------
        //  Build sorting clause
        // --------------------------------------------------
        
        // We'll assign this to a different variable since we
        // need to use this in two places
        
        $end = '';
	
		if (FALSE === $order_array['0'])
		{
			if ($sticky == 'off')
			{
				$end .= "ORDER BY t.entry_date";
			}
			else
			{
				$end .= "ORDER BY t.sticky desc, t.entry_date";
			}
			
			if ($sort_array['0'] == 'asc' || $sort_array['0'] == 'desc')
			{
				$end .= " ".$sort_array['0'];
			}
		}
		else
		{
			if ($sticky == 'off')
			{
				$end .= "ORDER BY ";
			}
			else
			{
				$end .= "ORDER BY t.sticky desc, ";
			}
			
			foreach($order_array as $key => $order)
			{
				if (in_array($order, array('view_count_one', 'view_count_two', 'view_count_three', 'view_count_four')))
				{
					$view_ct = substr($order, 10);
					$order	 = "view_count";
				}
				
				if ($key > 0) $end .= ", ";
			
				switch ($order)
				{
					case 'date' : 
						$end .= "t.entry_date";
					break;
					
					case 'edit_date' : 
						$end .= "t.edit_date";
					break;
					
					case 'expiration_date' : 
						$end .= "t.expiration_date";
					break;
					
					case 'title' : 
						$end .= "t.title";
					break;
					
					case 'url_title' : 
						$end .= "t.url_title";
					break;
					
					case 'view_count' : 
						$vc = $order.$view_ct;
					
						$end .= " t.{$vc} ".$sort_array[$key].", t.entry_date ".$sort_array[$key];
									
						$sort_array[$key] = FALSE;
					break;
					
					case 'comment_total' : 
						$end .= "t.comment_total ".$sort_array[$key].", t.entry_date ".$sort_array[$key];
						$sort_array[$key] = FALSE;
					break;
					
					case 'most_recent_comment' : 
						$end .= "t.recent_comment_date ".$sort_array[$key].", t.entry_date ".$sort_array[$key];
						$sort_array[$key] = FALSE;
					break;
					
					case 'username' : 
						$end .= "m.username";
					break;
					
					case 'screen_name' : 
						$end .= "m.screen_name";
					break;
					
					case 'custom_field' : 
						$end .= "wd.field_id_".$corder[$key];
					break;
					
					case 'random' : 
							$end = "ORDER BY rand()";  
							$sort_array[$key] = FALSE;
					break;
					
					default       : 
						$end .= "t.entry_date";
					break;
				}
				
				if ($sort_array[$key] == 'asc' || $sort_array[$key] == 'desc')
				{
					$end .= " ".$sort_array[$key];
				}
			}
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

        // ----------------------------------------------
        //  Is there an offset?
        // ----------------------------------------------
		// We do this hear so we can use the offset into next, then later one as well
		$offset = ( ! $TMPL->fetch_param('offset') OR ! is_numeric($TMPL->fetch_param('offset'))) ? '0' : $TMPL->fetch_param('offset');

		// ----------------------------------------
		//  Do we need pagination?
		// ----------------------------------------
		
		// We'll run the query to find out
		
		if ($this->paginate == TRUE)
		{		
			if ($this->field_pagination == FALSE)
			{			
				$this->pager_sql = $sql_a.$sql_b.$sql;
				$query = $DB->query($this->pager_sql);
				$total = $query->num_rows;
								
				// Adjust for offset
				if ($total >= $offset)
					$total = $total - $offset;
				
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
		
		if ($this->paginate == FALSE)
			$this->p_page = 0;

		// Adjust for offset
		$this->p_page += $offset;

		if ($this->display_by == '')
		{ 
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
		
		//exit($sql_a.$sql_b.$sql);
        
        if ($query->num_rows == 0)
        {
			$this->sql = '';
			return;
        }
        		        
        // ----------------------------------------------
        //  Build the full SQL query
        // ----------------------------------------------
        
        $this->sql = "SELECT ";

        if ($TMPL->fetch_param('category') || $TMPL->fetch_param('category_group') || $cat_id != '')                      
        {
        	// Using DISTINCT like this is bogus but since
        	// FULL OUTER JOINs are not supported in older versions
        	// of MySQL it's our only choice
        
			$this->sql .= " DISTINCT(t.entry_id), ";
        }
        
        // DO NOT CHANGE THE ORDER
        // The exp_member_data table needs to be called before the exp_members table.
	
		$this->sql .= " t.entry_id, t.weblog_id, t.forum_topic_id, t.author_id, t.ip_address, t.title, t.url_title, t.status, t.dst_enabled, t.view_count_one, t.view_count_two, t.view_count_three, t.view_count_four, t.allow_comments, t.comment_expiration_date, t.allow_trackbacks, t.sticky, t.entry_date, t.year, t.month, t.day, t.edit_date, t.expiration_date, t.recent_comment_date, t.comment_total, t.trackback_total, t.sent_trackbacks, t.recent_trackback_date,
						w.blog_title, w.blog_url, w.comment_url, w.tb_return_url, w.comment_moderate, w.weblog_html_formatting, w.weblog_allow_img_urls, w.weblog_auto_link_urls, w.enable_trackbacks, w.trackback_field, w.trackback_use_captcha, w.trackback_system_enabled, 
						m.username, m.email, m.url, m.screen_name, m.location, m.occupation, m.interests, m.aol_im, m.yahoo_im, m.msn_im, m.icq, m.signature, m.sig_img_filename, m.sig_img_width, m.sig_img_height, m.avatar_filename, m.avatar_width, m.avatar_height, m.photo_filename, m.photo_width, m.photo_height, m.group_id, m.member_id, m.bday_d, m.bday_m, m.bday_y, m.bio,
						md.*,
						wd.*
				FROM exp_weblog_titles		AS t
				LEFT JOIN exp_weblogs 		AS w  ON t.weblog_id = w.weblog_id 
				LEFT JOIN exp_weblog_data	AS wd ON t.entry_id = wd.entry_id 
				LEFT JOIN exp_members		AS m  ON m.member_id = t.author_id 
				LEFT JOIN exp_member_data	AS md ON md.member_id = m.member_id ";
                      
        if ($TMPL->fetch_param('category') || $TMPL->fetch_param('category_group') || $cat_id != '')                      
        {
        	/* --------------------------------
        	/*  We use LEFT JOIN when there is a 'not' so that we get 
        	/*  entries that are not assigned to a category.
        	/* --------------------------------*/
        	
        	if ((substr($TMPL->fetch_param('category_group'), 0, 3) == 'not' OR substr($TMPL->fetch_param('category'), 0, 3) == 'not') && $TMPL->fetch_param('uncategorized_entries') !== 'n')
        	{
        		$this->sql .= "LEFT JOIN exp_category_posts ON t.entry_id = exp_category_posts.entry_id
							   LEFT JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
        	}
        	else
        	{
        		$this->sql .= "INNER JOIN exp_category_posts ON t.entry_id = exp_category_posts.entry_id
                	           INNER JOIN exp_categories ON exp_category_posts.cat_id = exp_categories.cat_id ";
            }
        }
        
        $this->sql .= "WHERE t.entry_id IN (";
        
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
        
        unset($query);
        unset($entries);
        
		$this->sql = substr($this->sql, 0, -1).') '.$end;        
    }    
    // END




	// ----------------------------------------
	//  Create pagination
	// ----------------------------------------

	function create_pagination($count = 0, $query = '')
	{
		global $FNS, $TMPL, $IN, $REGX, $EXT, $PREFS, $SESS;
		
		// -------------------------------------------
		// 'weblog_module_create_pagination' hook.
		//  - Rewrite the pagination function in the Weblog module
		//  - Could be used to expand the kind of pagination available
		//  - Paginate via field length, for example
		//
			if (isset($EXT->extensions['weblog_module_create_pagination']))
			{
				$edata = $EXT->call_extension('weblog_module_create_pagination', $this);
				if ($EXT->end_script === TRUE) return;
			}
		//
        // -------------------------------------------
		
		
		if ($this->paginate == TRUE)
		{
			/* --------------------------------------
			/*  For subdomain's or domains using $template_group and $template
			/*  in path.php, the pagination for the main index page requires
			/*  that the template group and template are specified.
			/* --------------------------------------*/
		
			if (($IN->URI == '' OR $IN->URI == '/') && $PREFS->ini('template_group') != '' && $PREFS->ini('template') != '')
			{
				$this->basepath = $FNS->create_url($PREFS->ini('template_group').'/'.$PREFS->ini('template'), 1);
			}
			
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
					if ((in_array($this->reserved_cat_segment, explode("/", $IN->URI)) 
						AND $TMPL->fetch_param('dynamic') != 'off' 
						AND $TMPL->fetch_param('weblog'))
						|| (preg_match("#(^|\/)C(\d+)#", $IN->URI, $match) AND $dynamic))
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
				
				if ( ! ereg(SELF, $this->basepath) AND $PREFS->ini('site_index') != '')
				{
					$this->basepath .= SELF.'/';
				}
																	
				$first_url = (ereg("\.php/$", $this->basepath)) ? substr($this->basepath, 0, -1) : $this->basepath;
				
				if ($TMPL->fetch_param('paginate_base'))
				{				
					$pbase = $REGX->trim_slashes($TMPL->fetch_param('paginate_base'));
					
					$pbase = str_replace("&#47;index", "/", $pbase);
					
					if ( ! strstr($this->basepath, $pbase))
					{
						$this->basepath = $FNS->remove_double_slashes($this->basepath.'/'.$pbase.'/');
					}
				}				
								
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
        global $IN, $DB, $TMPL, $FNS, $SESS, $LOC, $PREFS, $REGX, $EXT;
        
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
				$cat_chunk[] = array($matches['2'][$j], $FNS->assign_parameters($matches['1'][$j]), $matches['0'][$j]);
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
					$matches['0'][$j] = str_replace(array(LD,RD), '', $matches['0'][$j]);
					
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
      	
      	// Are any of the custom fields dates?
      	
      	$custom_date_fields = array();
      	
      	if (count($this->dfields) > 0)
      	{
      		foreach ($this->dfields as $key => $val)
      		{
				if (preg_match_all("/".LD.$key."\s+format=[\"'](.*?)[\"']".RD."/s", $TMPL->tagdata, $matches))
				{
					for ($j = 0; $j < count($matches['0']); $j++)
					{
						$matches['0'][$j] = str_replace(array(LD,RD), '', $matches['0'][$j]);
						
						$custom_date_fields[$matches['0'][$j]] = $LOC->fetch_date_params($matches['1'][$j]);
					}
				}
			}
      	}
      	
        // ----------------------------------------
        //  "Search by Member" link
        // ----------------------------------------
		// We use this with the {member_search_path} variable
		
		$result_path = (preg_match("/".LD."member_search_path\s*=(.*?)".RD."/s", $TMPL->tagdata, $match)) ? $match['1'] : 'search/results';
		$result_path = str_replace(array("\"","'"), "", $result_path);
		
		$qs = ($PREFS->ini('force_query_string') == 'y') ? '' : '?';        
		$search_link = $FNS->fetch_site_index(0, 0).$qs.'ACT='.$FNS->fetch_action_id('Search', 'do_search').'&amp;result_path='.$result_path.'&amp;mbr=';
      	
        // ----------------------------------------
        //  Start the main processing loop
        // ----------------------------------------
        
        $tb_captcha = TRUE;
        $total_results = sizeof($this->query->result);

        foreach ($this->query->result as $count => $row)
        {         
            // Fetch the tag block containing the variables that need to be parsed
        
            $tagdata = $TMPL->tagdata;
            
            $row['count']			= $count+1;
            $row['total_results']	= $total_results;
            
            // -------------------------------------------
			// 'weblog_entries_tagdata' hook.
			//  - Take the entry data and tag data, do what you wish
			//
				if (isset($EXT->extensions['weblog_entries_tagdata']))
				{
					$tagdata = $EXT->call_extension('weblog_entries_tagdata', $tagdata, $row, $this);
					if ($EXT->end_script === TRUE) return $tagdata;
				}
			//
			// -------------------------------------------
              
            // ----------------------------------------
            //   Adjust dates if needed
            // ----------------------------------------
            
            // If the "dst_enabled" item is set in any given entry
            // we need to offset to the timestamp by an hour
            
            if ( ! isset($row['dst_enabled']))
            	$row['dst_enabled'] = 'n';
              
			if ($row['entry_date'] != '')
				$row['entry_date'] = $LOC->offset_entry_dst($row['entry_date'], $row['dst_enabled'], FALSE);

			if ($row['expiration_date'] != '' AND $row['expiration_date'] != 0)
				$row['expiration_date'] = $LOC->offset_entry_dst($row['expiration_date'], $row['dst_enabled'], FALSE);
				
			if ($row['comment_expiration_date'] != '' AND $row['comment_expiration_date'] != 0)
				$row['comment_expiration_date'] = $LOC->offset_entry_dst($row['comment_expiration_date'], $row['dst_enabled'], FALSE);				
                
			// ------------------------------------------
			//  Reset custom date fields
			// ------------------------------------------
			
			// Since custom date fields columns are integer types by default, if they 
			// don't contain any data they return a zero.
			// This creates a problem if conditionals are used with those fields.
			// For example, if an admin has this in a template:  {if mydate == ''}
			// Since the field contains a zero it would never evaluate true.
			// Therefore we'll reset any zero dates to nothing.
												
			if (count($this->dfields) > 0)
			{
				foreach ($this->dfields as $dkey => $dval)
				{	
					// While we're at it, kill any formatting
					$row['field_ft_'.$dval] = 'none';
					if (isset($row['field_id_'.$dval]) AND $row['field_id_'.$dval] == 0)
					{
						$row['field_id_'.$dval] = '';
					}
				}
			}
			// While we're at it, do the same for related entries.
			if (count($this->rfields) > 0)
			{
				foreach ($this->rfields as $rkey => $rval)
				{	
					$row['field_ft_'.$rval] = 'none';
				}
			}
				
            // ----------------------------------------
			//   Conditionals
			// ----------------------------------------
			
			$cond = $row;
			$cond['logged_in']			= ($SESS->userdata('member_id') == 0) ? 'FALSE' : 'TRUE';
			$cond['logged_out']			= ($SESS->userdata('member_id') != 0) ? 'FALSE' : 'TRUE';
			
			if (($row['comment_expiration_date'] > 0 && $LOC->now > $row['comment_expiration_date']) OR $row['allow_comments'] == 'n')
			{
				$cond['allow_comments'] = 'FALSE'; 
			}
			else
			{
				$cond['allow_comments'] = 'TRUE';  
			}
			
			foreach (array('avatar_filename', 'photo_filename', 'sig_img_filename') as $pv)
			{
				if ( ! isset($row[$pv]))
					$row[$pv] = '';
			}
			
			$cond['allow_trackbacks']		= ($row['allow_trackbacks'] == 'n' OR $row['trackback_system_enabled'] == '') ? 'FALSE' : 'TRUE';
			$cond['signature_image']		= ($row['sig_img_filename'] == '' OR $PREFS->ini('enable_signatures') == 'n' OR $SESS->userdata('display_signatures') == 'n') ? 'FALSE' : 'TRUE';
			$cond['avatar']					= ($row['avatar_filename'] == '' OR $PREFS->ini('enable_avatars') == 'n' OR $SESS->userdata('display_avatars') == 'n') ? 'FALSE' : 'TRUE';
			$cond['photo']					= ($row['photo_filename'] == '' OR $PREFS->ini('enable_photos') == 'n' OR $SESS->userdata('display_photos') == 'n') ? 'FALSE' : 'TRUE';
			$cond['forum_topic']			= ($row['forum_topic_id'] == 0) ? 'FALSE' : 'TRUE';
			$cond['not_forum_topic']		= ($row['forum_topic_id'] != 0) ? 'FALSE' : 'TRUE';
			$cond['comment_tb_total']		= $row['comment_total'] + $row['trackback_total'];
			$cond['category_request']		= ($this->cat_request === FALSE) ? 'FALSE' : 'TRUE';
			$cond['not_category_request']	= ($this->cat_request !== FALSE) ? 'FALSE' : 'TRUE';
			$cond['weblog']					= $row['blog_title'];
			$cond['author']					= ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'];
			$cond['photo_url']				= $PREFS->ini('photo_url', 1).$row['photo_filename'];
			$cond['photo_image_width']		= $row['photo_width'];
			$cond['photo_image_height']		= $row['photo_height'];
			$cond['avatar_url']				= $PREFS->ini('avatar_url', 1).$row['avatar_filename'];
			$cond['avatar_image_width']		= $row['avatar_width'];
			$cond['avatar_image_height']	= $row['avatar_height'];	
			$cond['signature_image_url']	= $PREFS->ini('sig_img_url', 1).$row['sig_img_filename'];
			$cond['signature_image_width']	= $row['sig_img_width'];
			$cond['signature_image_height']	= $row['sig_img_height'];
			$cond['relative_date']			= $LOC->format_timespan($LOC->now - $row['entry_date']);
			
			foreach($this->cfields as $key => $value)
			{
				$cond[$key] = ( ! isset($row['field_id_'.$value])) ? '' : $row['field_id_'.$value];
			}
			
			foreach($this->mfields as $key => $value)
			{
				if (isset($row['m_field_id_'.$value['0']]))
				{
					$cond[$key] = $this->TYPE->parse_type($row['m_field_id_'.$value['0']],
														  array(
																'text_format'   => $value['1'],
																'html_format'   => 'safe',
																'auto_links'    => 'y',
																'allow_img_url' => 'n'
															  )
														 );	
				}
			}
			
			$tagdata = $FNS->prep_conditionals($tagdata, $cond);

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
							
							$not_these		  = array();
							$these			  = array();
							$not_these_groups = array();
							$these_groups	  = array();
							
							if (isset($catval['1']['show']))
							{
								if (ereg("^not ", $catval['1']['show']))
								{
									$not_these = explode('|', trim(substr($catval['1']['show'], 3)));
								}
								else
								{
									$these = explode('|', trim($catval['1']['show']));
								}
							}
								
							if (isset($catval['1']['show_group']))
							{
								if (ereg("^not ", $catval['1']['show_group']))
								{
									$not_these_groups = explode('|', trim(substr($catval['1']['show_group'], 3)));
								}
								else
								{
									$these_groups = explode('|', trim($catval['1']['show_group']));
								}
							}
								
													
							foreach ($this->categories[$row['entry_id']] as $k => $v)
							{
								if (in_array($v['0'], $not_these) OR (isset($v['5']) && in_array($v['5'], $not_these_groups)))
								{
									continue;
								}
								elseif( (sizeof($these) > 0 && ! in_array($v['0'], $these)) OR
								 		(sizeof($these_groups) > 0 && isset($v['5']) && ! in_array($v['5'], $these_groups)))
								{
									continue;
								}
								
								$temp = $catval['0'];
							   
								if (preg_match_all("#".LD."path=(.+?)".RD."#", $temp, $matches))
								{
									foreach ($matches['1'] as $match)
									{																				
										if ($this->use_category_names == TRUE)
										{
											$temp = preg_replace("#".LD."path=.+?".RD."#", $FNS->remove_double_slashes($FNS->create_url($match).'/'.$this->reserved_cat_segment.'/'.$v['2'].'/'), $temp, 1);
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
								
								$cat_vars = array('category_name'			=> $v['2'],
												  'category_description'	=> (isset($v['4'])) ? $v['4'] : '',
												  'category_group'			=> (isset($v['5'])) ? $v['5'] : '',												  
												  'category_image'			=> $v['3'],
												  'category_id'				=> $v['0']);
							
								$temp = $FNS->prep_conditionals($temp, $cat_vars);
								
								$temp = str_replace(array(LD."category_id".RD,
														  LD."category_name".RD,
														  LD."category_image".RD,
														  LD."category_group".RD,														  
														  LD.'category_description'.RD),
													array($v['0'],
														  $v['2'],
														  $v['3'],
														  (isset($v['5'])) ? $v['5'] : '',														  
														  (isset($v['4'])) ? $v['4'] : ''
														  ),
													$temp);
										
								$cats .= $FNS->remove_double_slashes($temp);
							}
							
							$cats = rtrim(str_replace("&#47;", "/", $cats));
							
							if (is_array($catval['1']) AND isset($catval['1']['backspace']))
							{
								$cats = substr($cats, 0, - $catval['1']['backspace']);
							}							
							
							$cats = str_replace("/", "&#47;", $cats);
							
							$tagdata = str_replace($catval['2'], $cats, $tagdata);                        
						}
                    }
                    else
                    {
                        $tagdata = $TMPL->delete_var_pairs($key, 'categories', $tagdata);
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
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_hourly = $heading_date_hourly;    
                        }
                    } 
   
                    // ----------------------------------------
                    //  Weekly header
                    // ----------------------------------------
                    
                    elseif ($display == 'weekly')
                    {                         
                    	$heading_date_weekly = date('YW', $LOC->set_localized_time($row['entry_date']));
                    
                        if ($heading_date_weekly == $heading_flag_weekly)
                        {
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                            
                            $heading_flag_weekly = $heading_date_weekly;
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
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
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
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
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
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_heading', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_heading', $tagdata);
                        
                            $heading_flag_daily = $heading_date_daily;    
                        }
                    }                    
                }
                // END DATE HEADING
                
                
                // ----------------------------------------
                //  parse date footer
                // ----------------------------------------
                
                if (ereg("^date_footer", $key))
                {   
                    // Set the display preference
                    
                    $display = (is_array($val) AND isset($val['display'])) ? $val['display'] : 'daily';
                    
                    // ----------------------------------------
                    //  Hourly footer
                    // ----------------------------------------
                    
                    if ($display == 'hourly')
                    {
                        if ( ! isset($this->query->result[$row['count']]) OR 
                        	date('YmdH', $LOC->set_localized_time($row['entry_date'])) != date('YmdH', $LOC->set_localized_time($this->query->result[$row['count']]['entry_date'])))
                        {
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_footer', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_footer', $tagdata);
                        }
                    } 
   
                    // ----------------------------------------
                    //  Weekly footer
                    // ----------------------------------------
                    
                    elseif ($display == 'weekly')
                    {
                        if ( ! isset($this->query->result[$row['count']]) OR 
                        	date('YW', $LOC->set_localized_time($row['entry_date'])) != date('YW', $LOC->set_localized_time($this->query->result[$row['count']]['entry_date'])))
                        {
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_footer', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_footer', $tagdata);
                        }
                    } 
   
                    // ----------------------------------------
                    //  Monthly footer
                    // ----------------------------------------

                    elseif ($display == 'monthly')
                    {                           
                        if ( ! isset($this->query->result[$row['count']]) OR 
                        	date('Ym', $LOC->set_localized_time($row['entry_date'])) != date('Ym', $LOC->set_localized_time($this->query->result[$row['count']]['entry_date'])))
                        {
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_footer', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_footer', $tagdata);
                        }
                    } 
                    
                    // ----------------------------------------
                    //  Yearly footer
                    // ----------------------------------------

                    elseif ($display == 'yearly')
                    {
                        if ( ! isset($this->query->result[$row['count']]) OR 
                        	date('Y', $LOC->set_localized_time($row['entry_date'])) != date('Y', $LOC->set_localized_time($this->query->result[$row['count']]['entry_date'])))
                        {
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_footer', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_footer', $tagdata);
                        }
                    }
                    
                    // ----------------------------------------
                    //  Default (daily) footer
                    // ----------------------------------------

                    else
                    {
                        if ( ! isset($this->query->result[$row['count']]) OR 
                        	date('Ymd', $LOC->set_localized_time($row['entry_date'])) != date('Ymd', $LOC->set_localized_time($this->query->result[$row['count']]['entry_date'])))
                        {
                            $tagdata = $TMPL->swap_var_pairs($key, 'date_footer', $tagdata);
                        }
                        else
                        {        
                            $tagdata = $TMPL->delete_var_pairs($key, 'date_footer', $tagdata);
                        }
                    }                    
                }
                // END DATE FOOTER
                
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
							$tagdata = $TMPL->swap_var_single($key, $row[$item], $tagdata);
							
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
																																	
							$entry = $this->TYPE->parse_type( 
															   $row['field_id_'.$this->cfields[$item]], 
															   array(
																		'text_format'   => $row['field_ft_'.$this->cfields[$item]],
																		'html_format'   => $row['weblog_html_formatting'],
																		'auto_links'    => $row['weblog_auto_link_urls'],
																		'allow_img_url' => $row['weblog_allow_img_urls']
																	)
															 );
			
							$tagdata = $TMPL->swap_var_single($key, $entry, $tagdata);                
															 
							continue;                                                               
						}
					}
					
					// Garbage collection
					$val = '';
					$tagdata = $TMPL->swap_var_single($key, "", $tagdata);                
                }
            
            
				// ----------------------------------------
				//  parse {switch} variable
				// ----------------------------------------
				
				if (preg_match("/^switch\s*=.+/i", $key))
				{
					$sparam = $FNS->assign_parameters($key);
					
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
					
					$tagdata = $TMPL->swap_var_single($key, $sw, $tagdata);
				}
                                
                // ----------------------------------------
                //  parse entry date
                // ----------------------------------------
                
                if (isset($entry_date[$key]))
                {
					foreach ($entry_date[$key] as $dvar)
						$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], TRUE), $val);					

					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
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
	
						$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);	
                    }
                    else
                    {
                        $tagdata = str_replace(LD.$key.RD, "", $tagdata); 
                    }                
                }
            
                // ----------------------------------------
                //  GMT date - entry date in GMT
                // ----------------------------------------
                
                if (isset($gmt_entry_date[$key]))
                {
					foreach ($gmt_entry_date[$key] as $dvar)
						$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], FALSE), $val);					

					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
                }
                
                if (isset($gmt_date[$key]))
                {
					foreach ($gmt_date[$key] as $dvar)
						$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['entry_date'], FALSE), $val);					

					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
                }
                                
                // ----------------------------------------
                //  parse "last edit" date
                // ----------------------------------------
                
                if (isset($edit_date[$key]))
                {
					foreach ($edit_date[$key] as $dvar)
						$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $LOC->timestamp_to_gmt($row['edit_date']), TRUE), $val);					

					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
                }                
                
                // ----------------------------------------
                //  "last edit" date as GMT
                // ----------------------------------------
                
                if (isset($gmt_edit_date[$key]))
                {
					foreach ($gmt_edit_date[$key] as $dvar)
						$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $LOC->timestamp_to_gmt($row['edit_date']), FALSE), $val);					

					$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);					
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
	
						$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);	
                    }
                    else
                    {
                        $tagdata = str_replace(LD.$key.RD, "", $tagdata); 
                    }
                }                

              
                // ----------------------------------------
                //  parse profile path
                // ----------------------------------------
                                
                if (ereg("^profile_path", $key))
                {
					$tagdata = $TMPL->swap_var_single(
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
					$tagdata = $TMPL->swap_var_single(
														$key, 
														$search_link.$row['member_id'], 
														$tagdata
													 );
                }


                // ----------------------------------------
                //  parse comment_path or trackback_path
                // ----------------------------------------
                
                if (ereg("^comment_path", $key) || ereg("^trackback_path", $key) || ereg("^entry_id_path", $key) )
                {                       
					$tagdata = $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($FNS->extract_path($key).'/'.$row['entry_id']), 
														$tagdata
													 );
                }

                // ----------------------------------------
                //  parse URL title path
                // ----------------------------------------
                
                if (ereg("^url_title_path", $key))
                { 
					$path = ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX') ? $FNS->extract_path($key).'/'.$row['url_title'] : $row['url_title'];

					$tagdata = $TMPL->swap_var_single(
														$key, 
														$FNS->create_url($path, 1), 
														$tagdata
													 );
                }

                // ----------------------------------------
                //  parse title permalink
                // ----------------------------------------
                
                if (ereg("^title_permalink", $key))
                { 
					$path = ($FNS->extract_path($key) != '' AND $FNS->extract_path($key) != 'SITE_INDEX') ? $FNS->extract_path($key).'/'.$row['url_title'] : $row['url_title'];

					$tagdata = $TMPL->swap_var_single(
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
                
					$tagdata = $TMPL->swap_var_single(
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
                	
					$tagdata = $TMPL->swap_var_single($key, $path, $tagdata);
                }
           
                // ----------------------------------------
                //  {comment_url_title_auto_path}
                // ----------------------------------------
                
                if ($key == "comment_url_title_auto_path")
                { 
                	$path = ($row['comment_url'] == '') ? $row['blog_url'] : $row['comment_url'];
                	
					$tagdata = $TMPL->swap_var_single(
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
                	
					$tagdata = $TMPL->swap_var_single(
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
                    $tagdata = $TMPL->swap_var_single($val, ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'], $tagdata);
                }

                // ----------------------------------------
                //  {weblog}
                // ----------------------------------------
                
                if ($key == "weblog")
                {
                    $tagdata = $TMPL->swap_var_single($val, $row['blog_title'], $tagdata);
                }
                
                // ----------------------------------------
                //  {relative_date}
                // ----------------------------------------
                
                if ($key == "relative_date")
                {
                    $tagdata = $TMPL->swap_var_single($val, $LOC->format_timespan($LOC->now - $row['entry_date']), $tagdata);
                }

                // ----------------------------------------
                //  {trimmed_url} - used by Atom feeds
                // ----------------------------------------
                
                if ($key == "trimmed_url")
                {
					$blog_url = (isset($row['blog_url']) AND $row['blog_url'] != '') ? $row['blog_url'] : '';
				
					$blog_url = str_replace(array('http://','www.'), '', $blog_url);
					$xe = explode("/", $blog_url);
					$blog_url = current($xe);
                
                    $tagdata = $TMPL->swap_var_single($val, $blog_url, $tagdata);
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
					
                    $tagdata = $TMPL->swap_var_single($val, $blog_url, $tagdata);
                }
                
                // ----------------------------------------
                //  {url_or_email}
                // ----------------------------------------
                
                if ($key == "url_or_email")
                {
                    $tagdata = $TMPL->swap_var_single($val, ($row['url'] != '') ? $row['url'] : $this->TYPE->encode_email($row['email'], '', 0), $tagdata);
                }

                // ----------------------------------------
                //  {url_or_email_as_author}
                // ----------------------------------------
                
                if ($key == "url_or_email_as_author")
                {
                    $name = ($row['screen_name'] != '') ? $row['screen_name'] : $row['username'];
                    
                    if ($row['url'] != '')
                    {
                        $tagdata = $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$name."</a>", $tagdata);
                    }
                    else
                    {
                        $tagdata = $TMPL->swap_var_single($val, $this->TYPE->encode_email($row['email'], $name), $tagdata);
                    }
                }
                
                
                // ----------------------------------------
                //  {url_or_email_as_link}
                // ----------------------------------------
                
                if ($key == "url_or_email_as_link")
                {                    
                    if ($row['url'] != '')
                    {
                        $tagdata = $TMPL->swap_var_single($val, "<a href=\"".$row['url']."\">".$row['url']."</a>", $tagdata);
                    }
                    else
                    {                        
                        $tagdata = $TMPL->swap_var_single($val, $this->TYPE->encode_email($row['email']), $tagdata);
                    }
                }
               
               
                // ----------------------------------------
                //  parse {comment_tb_total}
                // ----------------------------------------
                
                if (ereg("^comment_tb_total$", $key))
                {                        
                    $tagdata = $TMPL->swap_var_single($val, ($row['comment_total'] + $row['trackback_total']), $tagdata);
                }
                
           
                // ----------------------------------------
                //  {signature}
                // ----------------------------------------
                
                if ($key == "signature")
                {        
					if ($SESS->userdata('display_signatures') == 'n' OR $row['signature'] == '' OR $SESS->userdata('display_signatures') == 'n')
					{			
						$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
					}
					else
					{
						$tagdata = $TMPL->swap_var_single($key,
														$this->TYPE->parse_type($row['signature'], array(
																					'text_format'   => 'xhtml',
																					'html_format'   => 'safe',
																					'auto_links'    => 'y',
																					'allow_img_url' => $PREFS->ini('sig_allow_img_hotlink')
																				)
																			), $tagdata);
					}
                }
                
                
                if ($key == "signature_image_url")
                {                 	
					if ($SESS->userdata('display_signatures') == 'n' OR $row['sig_img_filename'] == ''  OR $SESS->userdata('display_signatures') == 'n')
					{			
						$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
						$tagdata = $TMPL->swap_var_single('signature_image_width', '', $tagdata);
						$tagdata = $TMPL->swap_var_single('signature_image_height', '', $tagdata);
					}
					else
					{
						$tagdata = $TMPL->swap_var_single($key, $PREFS->ini('sig_img_url', TRUE).$row['sig_img_filename'], $tagdata);
						$tagdata = $TMPL->swap_var_single('signature_image_width', $row['sig_img_width'], $tagdata);
						$tagdata = $TMPL->swap_var_single('signature_image_height', $row['sig_img_height'], $tagdata);						
					}
                }

                if ($key == "avatar_url")
                {                 	
					if ($SESS->userdata('display_avatars') == 'n' OR $row['avatar_filename'] == ''  OR $SESS->userdata('display_avatars') == 'n')
					{			
						$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
						$tagdata = $TMPL->swap_var_single('avatar_image_width', '', $tagdata);
						$tagdata = $TMPL->swap_var_single('avatar_image_height', '', $tagdata);
					}
					else
					{
						$tagdata = $TMPL->swap_var_single($key, $PREFS->ini('avatar_url', 1).$row['avatar_filename'], $tagdata);
						$tagdata = $TMPL->swap_var_single('avatar_image_width', $row['avatar_width'], $tagdata);
						$tagdata = $TMPL->swap_var_single('avatar_image_height', $row['avatar_height'], $tagdata);						
					}
                }
                
                if ($key == "photo_url")
                {                 	
					if ($SESS->userdata('display_photos') == 'n' OR $row['photo_filename'] == ''  OR $SESS->userdata('display_photos') == 'n')
					{			
						$tagdata = $TMPL->swap_var_single($key, '', $tagdata);
						$tagdata = $TMPL->swap_var_single('photo_image_width', '', $tagdata);
						$tagdata = $TMPL->swap_var_single('photo_image_height', '', $tagdata);
					}
					else
					{
						$tagdata = $TMPL->swap_var_single($key, $PREFS->ini('photo_url', 1).$row['photo_filename'], $tagdata);
						$tagdata = $TMPL->swap_var_single('photo_image_width', $row['photo_width'], $tagdata);
						$tagdata = $TMPL->swap_var_single('photo_image_height', $row['photo_height'], $tagdata);						
					}
                }



                // ----------------------------------------
                //  parse {title}
                // ----------------------------------------
                
                if ($key == 'title')
                {                      
                	$row['title'] = str_replace(array('{', '}'), array('&#123;', '&#125;'), $row['title']);
                    $tagdata = $TMPL->swap_var_single($val,  $this->TYPE->light_xhtml_typography($row['title']), $tagdata);
                }
                    
                // ----------------------------------------
                //  parse basic fields (username, screen_name, etc.)
                // ----------------------------------------
                 
                if (isset($row[$val]))
                {                    
                    $tagdata = $TMPL->swap_var_single($val, $row[$val], $tagdata);
                }

               
                // ----------------------------------------
                //  parse custom date fields
                // ----------------------------------------

                if (isset($custom_date_fields[$key]))
                {
                	foreach ($this->dfields as $dkey => $dval)
                	{           
                		if (strpos($key, $dkey) === FALSE)
                			continue;
                			
                		if ($row['field_id_'.$dval] == 0 OR $row['field_id_'.$dval] == '')
                		{
							$tagdata = $TMPL->swap_var_single($key, '', $tagdata);	
							continue;
                		}
                		
                		$localize = TRUE;
						if (isset($row['field_dt_'.$dval]) AND $row['field_dt_'.$dval] != '')
						{ 
							$localize = TRUE;
							if ($row['field_dt_'.$dval] != '')
							{
								$row['field_id_'.$dval] = $LOC->offset_entry_dst($row['field_id_'.$dval], $row['dst_enabled']);
								$row['field_id_'.$dval] = $LOC->simpl_offset($row['field_id_'.$dval], $row['field_dt_'.$dval]);
								$localize = FALSE;
							}
                		}

						foreach ($custom_date_fields[$key] as $dvar)
							$val = str_replace($dvar, $LOC->convert_timestamp($dvar, $row['field_id_'.$dval], $localize), $val);	

							$tagdata = $TMPL->swap_var_single($key, $val, $tagdata);	
                	}
                }
         
                // ----------------------------------------
                //  Assign Related Entry IDs
                // ----------------------------------------
				
				// When an entry has related entries within it, since the related entry ID 
				// is stored in the custom field itself we need to pull it out and set it
				// aside so that when the related stuff is parsed out we'll have it.
				// We also need to modify the marker in the template so that we can replace
				// it with the right entry
								
                if (isset($this->rfields[$val]))
                {
                	// No relationship?  Ditch the marker
                	if ( !  isset($row['field_id_'.$this->cfields[$val]]) OR 
                		 $row['field_id_'.$this->cfields[$val]] == 0 OR
                		 ! preg_match_all("/".LD."REL\[".$val."\](.+?)REL".RD."/", $tagdata, $match)
                		)
                	{
						$tagdata = preg_replace("/".LD."REL\[".$val."\].+?REL".RD."/", "", $tagdata);
                	}
                	else
                	{
						for ($j = 0; $j < count($match['1']); $j++)
						{						
							$this->related_entries[] = $row['field_id_'.$this->cfields[$val]].'_'.$match['1'][$j];
							$tagdata = preg_replace("/".LD."REL\[".$val."\](.+?)REL".RD."/", LD."REL[".$row['field_id_'.$this->cfields[$val]]."][".$val."]\\1REL".RD, $tagdata);						
						}
						
						$tagdata = $TMPL->swap_var_single($val, '', $tagdata);                
                	}
				}
               
				// Clean up any unparsed relationship fields
				
				if (count($this->rfields) > 0)
				{
					$tagdata = preg_replace("/".LD."REL\[".preg_quote($val,'/')."\](.+?)REL".RD."/", "", $tagdata);
				}
				
				// ----------------------------------------
                //  Assign Reverse Related Entry IDs
                // ----------------------------------------
								
                if (preg_match_all("/".LD."REV_REL\[([^\]]+)\]REV_REL".RD."/", $tagdata, $match))
                {
                	for ($j = 0; $j < count($match['1']); $j++)
                	{
                		if ( ! isset($TMPL->reverse_related_data[$match['1'][$j]]))
                		{
                			continue;
                		}
                		
                		$this->reverse_related_entries[$row['entry_id']][$j] = $match['1'][$j];
                		
                		$tagdata = str_replace(	LD."REV_REL[".$match['1'][$j]."]REV_REL".RD,
                								LD."REV_REL[".$match['1'][$j]."][".$row['entry_id']."]REV_REL".RD,
                								$tagdata);
                	}
                }
				
                // ----------------------------------------
                //  parse custom weblog fields
                // ----------------------------------------
                                
                if (isset($this->cfields[$val]))
                {
                	if ( ! isset($row['field_id_'.$this->cfields[$val]]) OR $row['field_id_'.$this->cfields[$val]] == '')
                	{
						$entry = '';               
                	}
                	else
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
					
							$entry = $this->TYPE->parse_type( 
																$REGX->encode_ee_tags($row['field_id_'.$this->cfields[$val]]), 
																array(
																		'text_format'   => $row['field_ft_'.$this->cfields[$val]],
																		'html_format'   => $row['weblog_html_formatting'],
																		'auto_links'    => $row['weblog_auto_link_urls'],
																		'allow_img_url' => $row['weblog_allow_img_urls']
																	  )
															  );
                     	}
                     	
                    $tagdata = $TMPL->swap_var_single($val, $entry, $tagdata);                
                }
                
                // ----------------------------------------
                //  parse custom member fields
                // ----------------------------------------
                
                if ( isset( $this->mfields[$val]) AND isset($row['m_field_id_'.$this->mfields[$val]['0']]))
                {                
                    $tagdata = $TMPL->swap_var_single(
                                                        $val, 
                                                        $this->TYPE->parse_type( 
																				$row['m_field_id_'.$this->mfields[$val]['0']], 
																				array(
																						'text_format'   => $this->mfields[$val]['1'],
																						'html_format'   => 'safe',
																						'auto_links'    => 'y',
																						'allow_img_url' => 'n'
																					  )
																			  ), 
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
                            $categories .= $REGX->xml_convert($v['2']).',';
                        } 
                        
                        $categories = substr($categories, 0, -1);                           
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
            
				if ($this->display_tb_rdf == TRUE)
					$this->TYPE->encode_email = FALSE;		
				
				$tb_desc = $this->TYPE->parse_type( 
												   $FNS->char_limiter((isset($row['field_id_'.$row['trackback_field']])) ? $row['field_id_'.$row['trackback_field']] : ''), 
												   array(
															'text_format'   => 'none',
															'html_format'   => 'none',
															'auto_links'    => 'n',
															'allow_img_url' => 'y'
														)
												 );
												 
				$row['title'] = str_replace(array('{', '}'), array('&#123;', '&#125;'), $row['title']);												 
                $TB = array(
                             'about'        => $FNS->remove_double_slashes($ret_url.'/'.$row['url_title'].'/'),
                             'ping'         => $FNS->fetch_site_index(1, 0).'trackback/'.$row['entry_id'].'/'.$this->tb_captcha_hash,
                             'title'        => $REGX->xml_convert($row['title']),
                             'identifier'   => $FNS->remove_double_slashes($ret_url.'/'.$row['url_title'].'/'),
                             'subject'      => $REGX->xml_convert($categories),
                             'description'  => $REGX->xml_convert($tb_desc),
                             'creator'      => $REGX->xml_convert(($row['screen_name'] != '') ? $row['screen_name'] : $row['username']),
                             'date'         => $LOC->set_human_time($row['entry_date'], 0, 1).' GMT'
                            );
            
                $tagdata .= $this->trackback_rdf($TB);    
                
                $this->display_tb_rdf = FALSE;        
            }
            
            // -------------------------------------------
			// 'weblog_entries_tagdata_end' hook.
			//  - Take the final results of an entry's parsing and do what you wish
			//
				if (isset($EXT->extensions['weblog_entries_tagdata_end']))
				{
					$tagdata = $EXT->call_extension('weblog_entries_tagdata_end', $tagdata, $row, $this);
					if ($EXT->end_script === TRUE) return $tagdata;
				}
			//
			// -------------------------------------------
                        
            $this->return_data .= $tagdata;
            
        }
        // END FOREACH LOOP
        
        // Kill multi_field variable        
        $this->return_data = preg_replace("/".LD."multi_field\=[\"'](.+?)[\"']".RD."/s", "", $this->return_data);
        
        // Do we have backspacing?
        // This can only be used when RDF data is not present.
        
		if ($back = $TMPL->fetch_param('backspace') AND $this->display_tb_rdf != TRUE)
		{
			if (is_numeric($back))
			{
				$this->return_data = rtrim(str_replace("&#47;", "/", $this->return_data));
				$this->return_data = substr($this->return_data, 0, - $back);
				$this->return_data = str_replace("/", "&#47;", $this->return_data);
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
    //  Weblog Category Totals
    // ----------------------------------------
    
    // Need to finish this function.  It lets a simple list of cagegories
    // appear along with the post total.

    function category_totals()
    {
		$sql = "SELECT count( exp_category_posts.entry_id ) AS count, 
				exp_categories.cat_id, 
				exp_categories.cat_name 
				FROM exp_categories 
				LEFT JOIN exp_category_posts ON exp_category_posts.cat_id = exp_categories.cat_id 
				GROUP BY exp_categories.cat_id 
				ORDER BY group_id, parent_id, cat_order";
	}
	// END


	
	
	

    // ----------------------------------------
    //  Weblog Categories
    // ----------------------------------------

    function categories()
    {
        global $TMPL, $LOC, $FNS, $REGX, $DB, $LANG, $EXT;
        
        // -------------------------------------------
		// 'weblog_module_categories_start' hook.
		//  - Rewrite the displaying of categories, if you dare!
		//
			if (isset($EXT->extensions['weblog_module_categories_start']))
			{
				return $EXT->call_extension('weblog_module_categories_start');
			}
		//
        // -------------------------------------------
	
		if (USER_BLOG !== FALSE)
		{
		    $group_id = $DB->escape_str(UB_CAT_GRP);
		}
		else
		{
            $sql = "SELECT DISTINCT cat_group FROM exp_weblogs";
            
            if ($weblog = $TMPL->fetch_param('weblog'))
			{
				$xsql = $FNS->sql_andor_string($TMPL->fetch_param('weblog'), 'blog_name');
						
				if (substr($xsql, 0, 3) == 'AND') $xsql = substr($xsql, 3);
						
				$sql .= ' WHERE '.$xsql;
			}
		    
		    $query = $DB->query($sql);
		        
            if ($query->num_rows != 1)
            {
                return '';
            }
            
            $group_id = $query->row['cat_group'];
            
			if ($category_group = $TMPL->fetch_param('category_group'))
			{
				if (substr($category_group, 0, 4) == 'not ')
				{
					$x = explode('|', substr($category_group, 4));
					
					$groups = array_diff(explode('|', $group_id), $x);	
				}
				else
				{
					$x = explode('|', $category_group);
					
					$groups = array_intersect(explode('|', $group_id), $x);
				}
				
				if (sizeof($groups) == 0)
				{
					return '';
				}
				else
				{
					$group_id = implode('|', $groups);
				}
			}
            
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
											'show_empty'	=> $TMPL->fetch_param('show_empty')
										  )
								);
				
						
			if (count($this->category_list) > 0)
			{
				$i = 0;
				
				$id_name = ( ! $TMPL->fetch_param('id')) ? 'nav_categories' : $TMPL->fetch_param('id');
				$class_name = ( ! $TMPL->fetch_param('class')) ? 'nav_categories' : $TMPL->fetch_param('class');
				
				$this->category_list['0'] = '<ul id="'.$id_name.'" class="'.$class_name.'">'."\n";
			
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
			
				$query = $DB->query("SELECT cat_id, parent_id 
									 FROM exp_categories 
									 WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."')
									 ORDER BY group_id, parent_id, cat_order");
				
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
						LEFT JOIN exp_weblog_titles ON exp_category_posts.entry_id = exp_weblog_titles.entry_id
						WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."')
						AND exp_category_posts.cat_id IS NOT NULL 
						AND exp_weblog_titles.status != 'closed' ";
			
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
				
				if ($parent_only === TRUE)
				{
					$sql .= " AND parent_id = 0";
				}
				
				$sql .= " ORDER BY group_id, parent_id, cat_order";
				
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
				
				$sql .= " ORDER BY group_id, parent_id, cat_order";
				
				$query = $DB->query($sql);
					  
				if ($query->num_rows == 0)
					return false;        
			}
			else
			{		
				$sql = "SELECT exp_categories.cat_name, exp_categories.cat_image, exp_categories.cat_description, exp_categories.cat_id, exp_categories.parent_id 
						FROM exp_categories WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."') ";
						
				if ($parent_only === TRUE)
				{
					$sql .= " AND parent_id = 0";
				}
				
				$sql .= " ORDER BY group_id, parent_id, cat_order";
							
				$query = $DB->query($sql);
								  
				if ($query->num_rows == 0)
				{
					return '';
				}
			}  
			
			// Here we check the show parameter to see if we have any 
			// categories we should be ignoring or only a certain group of 
			// categories that we should be showing.  By doing this here before
			// all of the nested processing we should keep out all but the 
			// request categories while also not having a problem with having a 
			// child but not a parent.  As we all know, categories are not asexual.
		
			if ($TMPL->fetch_param('show') !== FALSE)
			{
				if (ereg("^not ", $TMPL->fetch_param('show')))
				{
					$not_these = explode('|', trim(substr($TMPL->fetch_param('show'), 3)));
				}
				else
				{
					$these = explode('|', trim($TMPL->fetch_param('show')));
				}
			}
			
			foreach($query->result as $row)
			{ 
				if (isset($not_these) && in_array($row['cat_id'], $not_these))
				{
					continue;
				}
				elseif(isset($these) && ! in_array($row['cat_id'], $these))
				{
					continue;
				}
			
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
				$chunk = $TMPL->tagdata;
				
				$cat_vars = array('category_name'			=> $val['3'],
								  'category_description'	=> $val['4'],
								  'category_image'			=> $val['5'],
								  'category_id'				=> $val['0']);
			
				$chunk = $FNS->prep_conditionals($chunk, $cat_vars);
			
				$chunk = str_replace(array(LD.'category_name'.RD,
										   LD.'category_description'.RD,
										   LD.'category_image'.RD,
										   LD.'category_id'.RD),
									 array($val['3'],
									 	   $val['4'],
									 	   $val['5'],
									 	   $val['0']),
									$chunk);
				
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
				$str = rtrim(str_replace("&#47;", "/", $str));
				$str = substr($str, 0, - $TMPL->fetch_param('backspace'));
				$str = str_replace("/", "&#47;", $str);
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
		    $group_id = $DB->escape_str(UB_CAT_GRP);
		    
		    $weblog_id = $DB->escape_str(UB_BLOG_ID);
		}
		else
		{
            $sql = "SELECT DISTINCT cat_group, weblog_id FROM exp_weblogs";
            
            if ($weblog = $TMPL->fetch_param('weblog'))
			{
				$xsql = $FNS->sql_andor_string($TMPL->fetch_param('weblog'), 'blog_name');
						
				if (substr($xsql, 0, 3) == 'AND') $xsql = substr($xsql, 3);
						
				$sql .= ' WHERE '.$xsql;
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
        
        if ($TMPL->fetch_param('show') !== FALSE)
		{
			$sql .= $FNS->sql_andor_string($TMPL->fetch_param('show'), 'exp_category_posts.cat_id').' ';
        }
        
			
		$orderby  = $TMPL->fetch_param('orderby');
					
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
		
		$sort = $TMPL->fetch_param('sort');
		
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
		
		$id_path = array();
		
		if (preg_match_all("#".LD."entry_id_path(=.+?)".RD."#", $tit_chunk, $matches)) 
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{			
				if ( ! isset($path[$matches['0'][$i]]))
				{
					$id_path[$matches['0'][$i]] = $FNS->create_url($FNS->extract_path($matches['1'][$i]));
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
					
					foreach($id_path as $tkey => $tval)
					{
						$chunk = str_replace($tkey, $FNS->remove_double_slashes($tval.'/'.$row['entry_id'].'/'), $chunk); 
					}
			
					$blog_array[$i.'_'.$row['cat_id']] = str_replace(LD.'title'.RD, $row['title'], $chunk);
					$i++;
				}
			}
			
			$this->category_tree(
									array(
											'group_id'		=> $group_id, 
											'weblog_id'		=> $weblog_id,
											'path'			=> $c_path,
											'template'		=> $cat_chunk,
											'blog_array' 	=> $blog_array,
											'parent_only'	=> $parent_only,
											'show_empty'	=> $TMPL->fetch_param('show_empty')
										  )
								);
						
			if (count($this->category_list) > 0)
			{			
				$id_name = ( ! $TMPL->fetch_param('id')) ? 'nav_cat_archive' : $TMPL->fetch_param('id');
			
				$this->category_list['0'] = '<ul id="'.$id_name.'">'."\n";
				
				foreach ($this->category_list as $val)
				{
					$str .= $val; 
				}
			}
		}
		else
		{		
			$sql = "SELECT DISTINCT (exp_categories.cat_id), exp_categories.cat_name, exp_categories.cat_description, exp_categories.cat_image, exp_categories.parent_id 
					FROM exp_categories ";
					
			if ($TMPL->fetch_param('show_empty') != 'no' AND $weblog_id != '')
			{
				$sql .= ", exp_category_posts ";
			}

			if ($TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= " LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id ";
			}
	
			if ($weblog_id != '')
			{
				$sql .= "LEFT JOIN exp_weblog_titles ON exp_category_posts.entry_id = exp_weblog_titles.entry_id ";
			}
	
			$sql .= " WHERE exp_categories.group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."') ";
			
			if ($weblog_id != '')
			{
				$sql .= "AND exp_weblog_titles.weblog_id = '".$weblog_id."' ";
			}
		
			if ($TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= "AND exp_category_posts.cat_id IS NOT NULL ";
			}
			
			if ($TMPL->fetch_param('show') !== FALSE)
			{
				$sql .= $FNS->sql_andor_string($TMPL->fetch_param('show'), 'exp_categories.cat_id').' ';
        	}
					
			if ($parent_only == TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY group_id, parent_id, cat_order";
		 	$query = $DB->query($sql);               
               
            if ($query->num_rows > 0)
            {
            	$used = array();
            
                foreach($query->result as $row)
                { 
					if ( ! isset($used[$row['cat_name']]))
					{
						$chunk = $cat_chunk;
						
						$cat_vars = array('category_name'			=> $row['cat_name'],
										  'category_description'	=> $row['cat_description'],
										  'category_image'			=> $row['cat_image'],
										  'category_id'				=> $row['cat_id']);
					
						$chunk = $FNS->prep_conditionals($chunk, $cat_vars);
					
						$chunk = str_replace( array(LD.'category_id'.RD,
													LD.'category_name'.RD,
													LD.'category_image'.RD,
													LD.'category_description'.RD),
											  array($row['cat_id'],
											  		$row['cat_name'],
											  		$row['cat_image'],
											  		$row['cat_description']),
											  $chunk);
												
						foreach($c_path as $ckey => $cval)
						{
							$cat_seg = ($this->use_category_names === TRUE) ? $this->reserved_cat_segment.'/'.$row['cat_name'] : 'C'.$row['cat_id'];
							$chunk = str_replace($ckey, $FNS->remove_double_slashes($cval.'/'.$cat_seg.'/'), $chunk); 
						}
					
						$str .= $chunk;
						$used[$row['cat_name']] = TRUE;
					}
										
					foreach($result->result as $trow)
					{
						if ($trow['cat_id'] == $row['cat_id'])
						{			
							$chunk = str_replace(array(LD.'title'.RD, LD.'category_name'.RD), 
												 array($trow['title'],$row['cat_name']),
												 $tit_chunk);
					
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
				$str = rtrim(str_replace("&#47;", "/", $str));
				$str = substr($str, 0, - $TMPL->fetch_param('backspace'));
				$str = str_replace("/", "&#47;", $str);
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
        global $FNS, $REGX, $DB, $TMPL, $FNS, $LOC;
        
        $default = array('group_id', 'weblog_id', 'path', 'template', 'depth', 'blog_array', 'parent_only', 'show_empty');
        
        foreach ($default as $val)
        {
        	$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
        }
        
        if ($group_id == '')
        {
            return false;
        }
        
		// -----------------------------------
		//  Are we showing empty categories
		// -----------------------------------
		
		// If we are only showing categories that have been assigned to entries
		// we need to run a couple queries and run a recursive function that
		// figures out whether any given category has a parent.
		// If we don't do this we will run into a problem in which parent categories
		// that are not assigned to a blog will be supressed, and therefore, any of its
		// children will be supressed also - even if they are assigned to entries.
		// So... we will first fetch all the category IDs, then only the ones that are assigned
		// to entries, and lastly we'll recursively run up the tree and fetch all parents.
		// Follow that?  No?  Me neither... 
           
		if ($show_empty == 'no')
		{	
			// First we'll grab all category ID numbers
		
			$query = $DB->query("SELECT cat_id, parent_id FROM exp_categories 
								 WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."') 
								 ORDER BY group_id, parent_id, cat_order");
			
			$all = array();
			
			// No categories exist?  Back to the barn for the night..
			if ($query->num_rows == 0)
				return false;
			
			foreach($query->result as $row)
			{
				$all[$row['cat_id']] = $row['parent_id'];
			}	
			
			// Next we'l grab only the assigned categories
		
			$sql = "SELECT DISTINCT(exp_categories.cat_id), parent_id 
					FROM exp_categories
					LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id 
					LEFT JOIN exp_weblog_titles ON exp_category_posts.entry_id = exp_weblog_titles.entry_id ";
					
			$sql .= "WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."') ";
			
			$sql .= "AND exp_category_posts.cat_id IS NOT NULL ";
			
			if ($weblog_id != '')
			{
				$sql .= "AND exp_weblog_titles.weblog_id = '".$weblog_id."' ";
			}
			
			$sql .= "AND exp_weblog_titles.status != 'closed' ";
			
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
			
			if ($parent_only === TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY group_id, parent_id, cat_order";
			
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
			
			$sql .= " ORDER BY group_id, parent_id, cat_order";
			
			$query = $DB->query($sql);
				  
			if ($query->num_rows == 0)
				return false;        
        }
		else
		{
			$sql = "SELECT DISTINCT(exp_categories.cat_id), exp_categories.parent_id, exp_categories.cat_name, exp_categories.cat_image, exp_categories.cat_description
					FROM exp_categories
					WHERE group_id IN ('".str_replace('|', "','", $DB->escape_str($group_id))."') ";
					
			if ($parent_only === TRUE)
			{
				$sql .= " AND parent_id = 0";
			}
			
			$sql .= " ORDER BY group_id, parent_id, cat_order";
			
			$query = $DB->query($sql);
				  
			if ($query->num_rows == 0)
				return false;
		}		
		
		// Here we check the show parameter to see if we have any 
		// categories we should be ignoring or only a certain group of 
		// categories that we should be showing.  By doing this here before
		// all of the nested processing we should keep out all but the 
		// request categories while also not having a problem with having a 
		// child but not a parent.  As we all know, categories are not asexual
		
		if ($TMPL->fetch_param('show') !== FALSE)
		{
			if (ereg("^not ", $TMPL->fetch_param('show')))
			{
				$not_these = explode('|', trim(substr($TMPL->fetch_param('show'), 3)));
			}
			else
			{
				$these = explode('|', trim($TMPL->fetch_param('show')));
			}
		}
		
		
		foreach($query->result as $row)
		{
			if (isset($not_these) && in_array($row['cat_id'], $not_these))
			{
				continue;
			}
			elseif(isset($these) && ! in_array($row['cat_id'], $these))
			{
				continue;
			}
		
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
				
				$chunk = $template;
				
				$cat_vars = array('category_name'			=> $val['1'],
								  'category_description'	=> $val['3'],
								  'category_image'			=> $val['2'],
								  'category_id'				=> $key);
			
				$chunk = $FNS->prep_conditionals($chunk, $cat_vars);
				
				$chunk = str_replace( array(LD.'category_id'.RD,
											LD.'category_name'.RD,
											LD.'category_image'.RD,
											LD.'category_description'.RD),
									  array($key,
									  		$val['1'],
									  		$val['2'],
									  		$val['3']),
									  $chunk);
            					
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
				
				$chunk = $template;
				
				$cat_vars = array('category_name'			=> $val['1'],
								  'category_description'	=> $val['3'],
								  'category_image'			=> $val['2'],
								  'category_id'				=> $key);
			
				$chunk = $FNS->prep_conditionals($chunk, $cat_vars);
				
				$chunk = str_replace( array(LD.'category_id'.RD,
											LD.'category_name'.RD,
											LD.'category_image'.RD,
											LD.'category_description'.RD),
									  array($key,
									  		$val['1'],
									  		$val['2'],
									  		$val['3']),
									  $chunk);
		
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
        global $IN, $TMPL, $FNS, $DB, $EXT;

		if ($IN->QSTR == '')
		{
		    return;
        }
        
        // -------------------------------------------
		// 'weblog_module_category_heading_start' hook.
		//  - Rewrite the displaying of category headings, if you dare!
		//
			if (isset($EXT->extensions['weblog_module_category_heading_start']))
			{
				$TMPL->tagdata = $EXT->call_extension('weblog_module_category_heading_start');
				if ($EXT->end_script === TRUE) return $TMPL->tagdata;
			}
		//
        // -------------------------------------------
        
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

		if (in_array($this->reserved_cat_segment, explode("/", $qstring)))
		{
			$qstring = preg_replace("/(.*?)".preg_quote($this->reserved_cat_segment)."\//i", '', $qstring);	

			$sql = "SELECT cat_group FROM exp_weblogs WHERE ";
			
			$sql .= (USER_BLOG !== FALSE) ? " weblog_id='".UB_BLOG_ID."'" : " blog_name='".$TMPL->fetch_param('weblog')."'";
				
			$query = $DB->query($sql);
			
			if ($query->num_rows == 1)
			{			
				$result = $DB->query("SELECT cat_id FROM exp_categories 
									  WHERE cat_name='".$DB->escape_str($qstring)."' 
									  AND group_id IN ('".str_replace('|', "','", $DB->escape_str($query->row['cat_group']))."')");
			
				if ($result->num_rows == 1)
				{
					$qstring = 'C'.$result->row['cat_id'];
				}
			}
		}

		// Is the category being specified by ID?

		if ( ! preg_match("#(^|\/)C(\d+)#", $qstring, $match))
		{					
			return '';
		}
				
		$query = $DB->query("SELECT cat_name, cat_description, cat_image FROM exp_categories WHERE cat_id = '".$DB->escape_str($match['2'])."'");
		
		if ($query->num_rows == 0)
		{
			return '';
		}
		
		$cat_vars = array('category_name'			=> $query->row['cat_name'],
						  'category_description'	=> $query->row['cat_description'],
						  'category_image'			=> $query->row['cat_image'],
						  'category_id'				=> $match['1']);
	
		$TMPL->tagdata = $FNS->prep_conditionals($TMPL->tagdata, $cat_vars);
		
		$TMPL->tagdata = str_replace( array(LD.'category_id'.RD,
											LD.'category_name'.RD,
											LD.'category_image'.RD,
											LD.'category_description'.RD),
							 	 	  array($match['2'],
											$query->row['cat_name'],
											$query->row['cat_image'],
											$query->row['cat_description']),
							  		  $TMPL->tagdata);

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
		
		if (strstr($qstring, '/'))
		{	
			$qstring = substr($qstring, 0, strpos($qstring, '/'));
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
        
        if (USER_BLOG !== FALSE)
		{
			$sql .= " weblog_id='".UB_BLOG_ID."'";
		}
		elseif($weblog = $TMPL->fetch_param('weblog'))
		{
			$sql .= ' '.$FNS->sql_andor_string($TMPL->fetch_param('weblog'), 'exp_weblogs.blog_name');
		}
		
		if ($TMPL->fetch_param('entry_id') != FALSE)
		{
			$sql .= ' '.$FNS->sql_andor_string($TMPL->fetch_param('entry_id'), 'entry_id', 't1');
		}

		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {			
        	$sql .= " AND t1.entry_date < ".$timestamp." ";
        }
        
        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= " AND (t1.entry_date > t2.entry_date OR (t1.entry_date >= t2.entry_date && t1.entry_id > t2.entry_id)) AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
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
        
		$path  = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		$path .= '/'.$query->row['url_title'].'/';
		
		$id_path  = (preg_match("#".LD."id_path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		$id_path .= '/'.$query->row['entry_id'].'/';
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);	
		$TMPL->tagdata = preg_replace("#".LD."id_path=.+?".RD."#", $id_path, $TMPL->tagdata);
		$TMPL->tagdata = str_replace(LD."url_title".RD, $query->row['url_title'], $TMPL->tagdata);	
		$TMPL->tagdata = str_replace(LD."title".RD, $query->row['title'], $TMPL->tagdata);	
		$TMPL->tagdata = str_replace(LD."next_entry->title".RD, $query->row['title'], $TMPL->tagdata);	

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
		
		if (strstr($qstring, '/'))
		{	
			$qstring = substr($qstring, 0, strpos($qstring, '/'));
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
        
        if (USER_BLOG !== FALSE)
		{
			$sql .= " weblog_id='".UB_BLOG_ID."'";
		}
		elseif($weblog = $TMPL->fetch_param('weblog'))
		{
			$sql .= ' '.$FNS->sql_andor_string($TMPL->fetch_param('weblog'), 'exp_weblogs.blog_name');
		}
		
		if ($TMPL->fetch_param('entry_id') != FALSE)
		{
			$sql .= ' '.$FNS->sql_andor_string($TMPL->fetch_param('entry_id'), 'entry_id', 't1');
		}
        
		$timestamp = ($TMPL->cache_timestamp != '') ? $LOC->set_gmt($TMPL->cache_timestamp) : $LOC->now;
		
        if ($TMPL->fetch_param('show_future_entries') != 'yes')
        {
        		$sql .= " AND t1.entry_date < ".$timestamp." ";
        }

        if ($TMPL->fetch_param('show_expired') != 'yes')
        {
			$sql .= " AND (t1.entry_date < t2.entry_date OR (t1.entry_date <= t2.entry_date && t1.entry_id < t2.entry_id)) AND (t1.expiration_date = 0 || t1.expiration_date > ".$timestamp.") ";
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
        
		$path  = (preg_match("#".LD."path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		$path .= '/'.$query->row['url_title'].'/';

		$id_path  = (preg_match("#".LD."id_path=(.+?)".RD."#", $TMPL->tagdata, $match)) ? $FNS->create_url($match['1']) : $FNS->create_url("SITE_INDEX");
		$id_path .= '/'.$query->row['entry_id'].'/';
		
		$TMPL->tagdata = preg_replace("#".LD."path=.+?".RD."#", $path, $TMPL->tagdata);
		$TMPL->tagdata = preg_replace("#".LD."id_path=.+?".RD."#", $id_path, $TMPL->tagdata);
		$TMPL->tagdata = str_replace(LD."url_title".RD, $query->row['url_title'], $TMPL->tagdata);	
		$TMPL->tagdata = str_replace(LD."title".RD, $query->row['title'], $TMPL->tagdata);	
		$TMPL->tagdata = str_replace(LD."prev_entry->title".RD, $query->row['title'], $TMPL->tagdata);		

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
        
		// ----------------------------------------------
        // Add status declaration
        // ----------------------------------------------
                        
        if ($status = $TMPL->fetch_param('status'))
        {
			$status = str_replace('Open',   'open',   $status);
			$status = str_replace('Closed', 'closed', $status);
			
			$sstr = $FNS->sql_andor_string($status, 'status');
			
			if ( ! eregi("'closed'", $sstr))
			{
				$sstr .= " AND status != 'closed' ";
			}
			
			$sql .= $sstr;
        }
        else
        {
            $sql .= "AND status = 'open' ";
        }
        
        $sql .= " ORDER BY entry_date";
		
		switch ($TMPL->fetch_param('sort'))
		{
			case 'asc'	: $sql .= " asc";
				break;
			case 'desc'	: $sql .= " desc";
				break;
			default		: $sql .= " desc";
				break;
		} 
                
        if ($TMPL->fetch_param('limit'))
        {
            $sql .= " LIMIT ".$TMPL->fetch_param('limit');  
        }
                
        $query = $DB->query($sql);

        if ($query->num_rows == 0)
        {
            return '';
        }
        
        $year_limit   = ($TMPL->fetch_param('year_limit') !== FALSE) ? $TMPL->fetch_param('year_limit') : 50;
        $total_years  = 0;
        $current_year = '';
        
        foreach ($query->result as $row)
        { 
            $tagdata = $TMPL->tagdata;
							
			$month = (strlen($row['month']) == 1) ? '0'.$row['month'] : $row['month'];
			$year  = $row['year'];
				
            $month_name = $LOC->localize_month($month);
            
            // ----------------------------------------
            //  Dealing with {year_heading}
            // ----------------------------------------
            
			if (isset($TMPL->var_pair['year_heading']))
			{
				if ($year == $current_year)
				{
					$tagdata = $TMPL->delete_var_pairs('year_heading', 'year_heading', $tagdata);
				}
				else
				{
					$tagdata = $TMPL->swap_var_pairs('year_heading', 'year_heading', $tagdata);
					
					$total_years++;
            	
					if ($total_years > $year_limit)
					{	
						break;
					}
				}
				
				$current_year = $year;
			}
            
            // ----------------------------------------
            //  parse path
            // ----------------------------------------
                        
            foreach ($TMPL->var_single as $key => $val)
            {              
                if (ereg("^path", $key))
                { 
                    $tagdata = $TMPL->swap_var_single(
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
                    $tagdata = $TMPL->swap_var_single($key, $LANG->line($month_name['1']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (short)
                // ----------------------------------------
                
                if ($key == 'month_short')
                {    
                    $tagdata = $TMPL->swap_var_single($key, $LANG->line($month_name['0']), $tagdata);
                }
                
                // ----------------------------------------
                //  parse month (numeric)
                // ----------------------------------------
                
                if ($key == 'month_num')
                {    
                    $tagdata = $TMPL->swap_var_single($key, $month, $tagdata);
                }
                
                // ----------------------------------------
                //  parse year
                // ----------------------------------------
                
                if ($key == 'year')
                {    
                    $tagdata = $TMPL->swap_var_single($key, $year, $tagdata);
                }
                
                // ----------------------------------------
                //  parse year (short)
                // ----------------------------------------
                
                if ($key == 'year_short')
                {    
                    $tagdata = $TMPL->swap_var_single($key, substr($year, 2), $tagdata);
                }
             }
             
             $return .= trim($tagdata)."\n";
         }
             
        return $return;    
    }
    // END


    // ----------------------------------------
    //  Related Categories Mode
    // ----------------------------------------

	// This function shows entries that are in the same category as
	// the primary entry being shown.  It calls the main "weblog entries"
	// function after setting some variables to control the content.
	//
	// Note:  We have deprecated the calling of this tag directly via its own tag.
	// Related entries are now shown using the standard {exp:weblog:entries} tag.
	// The reason we're deprecating it is to avoid confusion since the weblog tag
	// now supports relational capability via a pair of {related_entries} tags.
	// 
	// To show "related entries" the following parameter is added to the {exp:weblog:entries} tag:
	//
	// related_categories_mode="on"
	
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
		
		// --------------------------------------
		//  Make sure to only get one segment
		// --------------------------------------
		
		if (strstr($qstring, '/'))
		{	
			$qstring = substr($qstring, 0, strpos($qstring, '/'));
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
			return $TMPL->no_results();
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
			return $TMPL->no_results();
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
		
		if ($TMPL->fetch_param('custom_fields') !== FALSE && $TMPL->fetch_param('custom_fields') == 'on')
        {
        	$this->fetch_custom_weblog_fields();
        }

        $this->build_sql_query();
        
        if ($this->sql == '')
        {
        	return $TMPL->no_results();
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
        
        if ($TMPL->fetch_param('member_data') !== FALSE && $TMPL->fetch_param('member_data') == 'on')
        {
        	$this->fetch_custom_member_fields();
        }
        
        $this->parse_weblog_entries();
        		
		return $this->return_data;
	}
	// END
        
    
    
    // ----------------------------------------
    //  Weblog Calendar
    // ----------------------------------------
    
    function calendar()
    {
    	global $EXT;
    	
    	// -------------------------------------------
		// 'weblog_module_calendar_start' hook.
		//  - Rewrite the displaying of the calendar tag
		//
			if (isset($EXT->extensions['weblog_module_calendar_start']))
			{
				$edata = $EXT->call_extension('weblog_module_calendar_start');
				if ($EXT->end_script === TRUE) return $edata;
			}
		//
        // -------------------------------------------
    
    	if ( ! class_exists('Weblog_calendar'))
		{
			require PATH_MOD.'weblog/mod.weblog_calendar.php';
		}
		
		$WC = new Weblog_calendar();
		return $WC->calendar();
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
		if ( ! class_exists('Weblog_standalone'))
		{
			require PATH_MOD.'weblog/mod.weblog_standalone.php';
		}
		
		$WS = new Weblog_standalone();
		$WS->insert_new_entry();
	}
	// END


    // ----------------------------------------
    //  Stand-alone version of the entry form
    // ----------------------------------------
    
    function entry_form($return_form = FALSE, $captcha = '')
    {
       if ( ! class_exists('Weblog_standalone'))
		{
			require PATH_MOD.'weblog/mod.weblog_standalone.php';
		}
		
		$WS = new Weblog_standalone();
		return $WS->entry_form($return_form, $captcha); 
    }
    // END
      
}
// END CLASS
?>