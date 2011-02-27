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
 File: cp.utilities.php
-----------------------------------------------------
 Purpose: Utilities
=====================================================
*/

if ( ! defined('EXT'))
{
    exit('Invalid file request');
}


class Utilities {


    // -------------------------------------------
    //  Plugin Manager
    // -------------------------------------------    

	function plugin_manager($message = '')
	{
        global $DSP, $IN, $PREFS, $LANG, $FNS;
     
		if ( ! @include_once(PATH_LIB.'pclzip.lib.php'))
		{
			return $DSP->no_access_message('PclZip Library does not appear to be installed.  It is required.');
		}     
     
		$is_writable = (is_writable(PATH_PI)) ? TRUE : FALSE;
        
        $plugins = array();
		$info 	= array();
		
		if ($fp = @opendir(PATH_PI))
        { 
            while (false !== ($file = readdir($fp)))
            {
                if (eregi('php', $file) && $file !== '.' && $file !== '..' &&  substr($file, 0, 3) == 'pi.') 
                {
					if ( ! @include_once(PATH_PI.$file))
						continue;
                
                    $name = str_replace('pi.', '', $file);
                	$name = str_replace(EXT, '', $name);
                                    
					$plugins[] = $name;
					                    
                    $info[$name] = array_unique($plugin_info);
                }
            }         
			
			closedir($fp); 
        } 	
          		
  		if ( array_search('magpie', $plugins) )
      		$r = '<div style="float: left; width: 69%; margin-right: 2%;">';
      	else
      		$r = '<div style="float: left; width: 100%;">';
  		        
		if ($is_writable)
		{
              $r .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=plugin_remove_conf', 'target', 'post').
              $DSP->toggle();
        }
        
        if ($message != '')
        {
        		$r .= $DSP->qdiv('itemWrapper', $DSP->qdiv('highlight', $message));
        }
              
        $r .= $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 

        $r .= $DSP->table('', '0', '10', '100%').
              $DSP->tr().
              $DSP->td('tableHeadingLargeBold', ($is_writable) ? '97%' : '100%', '').
              count($plugins).' '.$LANG->line('plugin_installed').
              $DSP->td_c();
              
		if ($is_writable)
		{
			$r .= $DSP->td('tableHeadingBold', '3%', '').
				  $DSP->input_checkbox('toggleflag', '', '', "onclick=\"toggle(this);\"").
				  $DSP->td_c();
		}
		
		$r .= $DSP->tr_c();
  
        if (count($plugins) == 0)
        {
            $r .= $DSP->tr().
                  $DSP->td('tableCellTwo', '', '2').
                  '<b>'.$LANG->line('no_plugins_exist').'</b>'.
                  $DSP->td_c().
                  $DSP->tr_c();
        }  

        $i = 0;
        
        if (count($plugins) > 0)
        {
            foreach ($plugins as $plugin)
            {
				$version = '(v.'.trim($info[$plugin]['pi_version']).')';
				$update = '';
				
				$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
				
				$name = $DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_info'.AMP.'name='.$plugin, $info[$plugin]['pi_name']));
				$description = $info[$plugin]['pi_description'];
				
				$r .= $DSP->tr();
				
				$r .= $DSP->table_qcell($style, $name.' '.$version.' '.$update.$DSP->br().$description, ($is_writable) ? '85%' : '100%');
		  
				if ($is_writable)
				{
					$r .= $DSP->table_qcell($style, $DSP->input_checkbox('toggle[]', $plugin), '15%');
				}
				
				$r .= $DSP->tr_c();
            }
        }
        
        $r .= $DSP->table_c();
        
        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();
             
		if ($is_writable)
		{
             $r .= $DSP->div('', 'right')
				 .$DSP->input_submit($LANG->line('plugin_remove'))
				 .$DSP->div_c()
				 .$DSP->form_c();
		}
		
		$r .= $DSP->div_c();

             
        // -------------------------------------------
        //  Latest Plugin Table
        // -------------------------------------------
        
        // Do we have the Magpie plugin so we can parse the EE plugin RSS feed?
        if (array_search('magpie', $plugins) )
        {
            // Latest plugin RSS feed goes here
            $code = '';
            // THT: Vinay, 1/16/05: Because allow_url_fopen is set to no, the 
            // commented line won't work, so I replaced it with the following
            // line, which is a mirror of the same URL, placed there by
            // an hourly cron job.
            //$fp = fopen('http://plugins.pmachine.com/index.php/feeds/download_2.0/', 'rb');
            $fp = fopen('/usr/www/users/vinay/vinay_exp/plugins.xml', 'rb');
            // END THT
            while ( ! feof($fp) )
            {
                $code .= fgets($fp, 4096);
            }
            fclose($fp);
            
            $plugins = new MagpieRSS($code);
			
			$i = 0;
			
			if (count($plugins->items) > 0)
			{
				// Example pagination: &perpage=10&page=10&sortby=alpha
				$paginate = '';
				$extra = ''; // Will hold sort method
				$total_rows = count($plugins->items);
				$perpage = ( ! $IN->GBL('perpage')) ? 10 : $IN->GBL('perpage');
				$page = ( ! $IN->GBL('page')) ? 0 : $IN->GBL('page');
				$sortby = ( ! $IN->GBL('sortby')) ? '' : $IN->GBL('sortby');
				$base = 'index.php?C=admin'.AMP.'M=utilities'.AMP.'P=plugin_manager';
				
				if ($sortby == 'alpha')
				{
					sort($plugins->items);
					$extra = AMP.'sortby=alpha';
					$link = $DSP->anchor($base, $LANG->line('plugin_by_date'));
					$title = $LANG->line('plugins').$DSP->qspan('defaultSmall', $LANG->line('plugin_by_letter').' : '.$link);
				}
				else
				{
					$link = $DSP->anchor($base.AMP.'sortby=alpha', $LANG->line('plugin_by_letter'));
					$title = $LANG->line('plugins').$DSP->qspan('defaultSmall', $LANG->line('plugin_by_date').' : '.$link);
				}

				$ten_plugins = array_slice($plugins->items, $page, $perpage-1);
				
				// Latest Plugins Table
				$r .= '<div style="float: left; width: 29%; clear: right;">'.
					$DSP->table('tableBorder', '0', '0', '').
					$DSP->tr().
					$DSP->td('tablePad');
		
				$r .= $DSP->table('', '0', '10', '100%').
					$DSP->tr().
					$DSP->td('tableHeadingLargeBold', '', '').
					$title.
					$DSP->td_c().
					$DSP->tr_c();
				
				$curl_installed = ( ! extension_loaded('curl') || ! function_exists('curl_init')) ? FALSE : TRUE;
				
				$qm = ($PREFS->ini('force_query_string') == 'y') ? '' : '?';	
				
				foreach ($ten_plugins as $item)
				{
					$attr = explode('|', $item['dc']['subject']);
					$dl = $attr[0];
					$version = '(v.'.$attr[1].')';
					$require = ( ! $attr[2] ) ? '' : $DSP->br().$DSP->qspan('highlight', $LANG->line('plugin_requires').': '.$attr[2]);
					
					$name = $DSP->qspan('defaultBold', $DSP->anchor($FNS->fetch_site_index().$qm.'URL='.$item['link'], $item['title']));
					$description = $FNS->word_limiter($item['description'], '20');
					
					$install = ( ! class_exists('PclZip') || ! $is_writable || ! $curl_installed) ? '' : $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_install'.AMP.'file='.$dl, '<span style=\'color:#009933;\'>'.$LANG->line('plugin_install').'</span>');
					
					$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
					
					$r .= $DSP->tr();
					
					$r .= $DSP->table_qcell($style, $name.' '.$version.$DSP->nbs().$require.$DSP->qdiv('itemWrapper', $description).$install, '60%');

					$r .= $DSP->tr_c();
				}
				
				$r .= $DSP->table_c();
				
				$r .= $DSP->td_c()   
					.$DSP->tr_c()      
					.$DSP->table_c();
					
				if ($total_rows > $perpage)
				{		 
					$paginate = $DSP->pager(  BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_manager'.$extra.AMP.'perpage='.$perpage,
											  $total_rows, 
											  $perpage,
											  $page,
											  'page'
											);
				}
				
				$r .= $paginate
					.$DSP->div_c();
			}
			
		}
                
        $DSP->title  = $LANG->line('plugin_manager');
        $DSP->crumb  = $LANG->line('plugin_manager');
        $DSP->body   = &$r;  
	}
	// END


    // -------------------------------------------
    //  Plugin Info
    // -------------------------------------------    

    function plugin_info()
    {
		global $IN, $DSP, $LANG, $FNS, $PREFS;
		
		$name = $IN->GBL('name');
		
		if ( ! @include(PATH_PI.'pi.'.$name.EXT))
		{
			return $DSP->error_message('Unable to load the following plugin: '.$name.EXT);
		}     
		
		$qm = ($PREFS->ini('force_query_string') == 'y') ? '' : '?';
			                
        $DSP->title  = ucwords(str_replace("_", " ", $name));
        $DSP->crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_manager', $LANG->line('plugin_manager')).
        $DSP->crumb  = $DSP->crumb_item(ucwords(str_replace("_", " ", $name)));

        $i = 0;
        
        $r  = $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 

        $r .= $DSP->table('', '0', '10', '100%').
              $DSP->tr().
              $DSP->td('tableHeadingLargeBold', '', '2').
              $LANG->line('plugin_information').
              $DSP->td_c().
              $DSP->tr_c();  
             		
		if ( ! isset($plugin_info) OR ! is_array($plugin_info))
		{
			$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
			
			$name = ucwords(str_replace("_", " ", $name));

			$r .= $DSP->tr();
			$r .= $DSP->table_qcell($style, $DSP->qspan('defaultBold', $LANG->line('pi_name')), '30%');
			$r .= $DSP->table_qcell($style, $DSP->qspan('defaultBold', $name), '70%');
			$r .= $DSP->tr_c();
			
			$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';

			$r .= $DSP->tr();
			$r .= $DSP->td($style, '', '2').$DSP->qspan('default', $LANG->line('no_additional_info'));
			$r .= $DSP->td_c();
			$r .= $DSP->tr_c();
              
        }
        else
        {        
			foreach ($plugin_info as $key => $val)
			{ 
				$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
				
				$item = ($LANG->line($key) != FALSE) ? $LANG->line($key) : ucwords(str_replace("_", " ", $key));
				
				if ($key == 'pi_author_url')
				{
					if (substr($val, 0, 4) != "http") 
						$val = "http://".$val; 
						
						$val = $DSP->anchor($FNS->fetch_site_index().$qm.'URL='.$val, $val, '', 1);
				}
				
				if ($key == 'pi_usage')
					$val = nl2br(htmlspecialchars($val));
					
				$r .= $DSP->tr();
				$r .= $DSP->table_qcell($style, $DSP->qspan('defaultBold', $item), '30%', 'top');
				$r .= $DSP->table_qcell($style, $DSP->qspan('default', $val), '70%');
				$r .= $DSP->tr_c();
			}
  		}

        $r .= $DSP->table_c();
        
        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      

		$DSP->body = $r;
	}
	// END
	
	// -------------------------------------------
    //  Plugin Extraction from ZIP file
    // -------------------------------------------
	
	function plugin_install()
	{		
        global $IN, $DSP, $LANG;
        
		if ( ! @include_once(PATH_LIB.'pclzip.lib.php'))
		{
			return $DSP->error_message($LANG->line('plugin_zlib_missing'));
		}
		
		if ( ! is_writable(PATH_PI))
		{
			return $DSP->error_message($LANG->line('plugin_folder_not_writable'));
		}
		
		if ( ! extension_loaded('curl') || ! function_exists('curl_init'))
		{
			return $DSP->error_message($LANG->line('plugin_no_curl_support'));
		}
        
        $file = $IN->GBL('file');
                
        $local_name = basename($file);
        $local_file = PATH_PI.$local_name;
        
		// Get the remote file
		$c = curl_init($file);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
		$code = curl_exec($c);
		curl_close($c);
	    
	    $file_info = pathinfo($local_file);
	
	    if ($file_info['extension'] == 'txt' ) // Get rid of any notes/headers in the TXT file
        {
			$code = strstr($code, '<?php');
		}
	    
	    if ( ! $fp = fopen($local_file, 'wb'))
	    {
			return $DSP->error_message($LANG->line('plugin_problem_creating_file'));
	    }
	    
	    flock($fp, LOCK_EX);
	    fwrite($fp, $code);
	    flock($fp, LOCK_UN);
	    fclose($fp);

	    @chmod($local_file, 0777);
	    
        // Check file information so we know what to do with it
        
		if ($file_info['extension'] == 'txt' ) // We've got a TXT file!
        {
			$new_file = basename($local_file, '.txt');
			if ( ! rename($local_file, PATH_PI.$new_file))
			{
				$message = $LANG->line('plugin_install_other');
			}
			else
			{
				@chmod($new_file, 0777);
				$message = $LANG->line('plugin_install_success');
			}
        }
        else if ($file_info['extension'] == 'zip' ) // We've got a ZIP file!
        {
        	// Unzip and install plugin
			if (class_exists('PclZip'))
			{
				$zip = new PclZip($local_file);
				chdir(PATH_PI);
				$ok = @$zip->extract('');
				
				if ($ok)
				{
					$message = $LANG->line('plugin_install_success');
					unlink($local_file);
				}
				else
				{
					$message = $LANG->line('plugin_error_uncompress');
				}
			}
			else
			{
				$message = $LANG->line('plugin_error_no_zlib');
			}
        }
        else
        {
        		$message = $LANG->line('plugin_install_other');
        }
		
		return Utilities::plugin_manager($message);

	}
	// END
	
	
	// -------------------------------------------
    //  Plugin Removal Confirmation
    // -------------------------------------------    
    
    function plugin_remove_confirm()
    {
        global $IN, $DSP, $LANG;

        $r  = $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=plugin_remove');
        
        $i = 0;
        
        foreach ($_POST as $key => $val)
        {        
            if (strstr($key, 'toggle') AND ! is_array($val))
            {
                $r .= $DSP->input_hidden('deleted[]', $val);
                
                $i++;
            }        
        }
        
        $r .= $DSP->heading($LANG->line('plugin_delete_confirm'));
        $r .= $DSP->div();
        
        if ($i == 1)
            $r .= '<b>'.$LANG->line('plugin_single_confirm').'</b>';
        else
            $r .= '<b>'.$LANG->line('plugin_multiple_confirm').'</b>';
            
        $r .= $DSP->br(2).
              $DSP->qdiv('alert', $LANG->line('action_can_not_be_undone')).
              $DSP->br().
              $DSP->input_submit($LANG->line('deinstall')).
              $DSP->div_c().
              $DSP->form_c();

        $DSP->title = $LANG->line('plugin_delete_confirm');
        $DSP->crumb = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_manager', $LANG->line('plugin_manager')).
        $DSP->crumb = $DSP->crumb_item($LANG->line('plugin_delete_confirm'));         
        $DSP->body  = &$r;
    }
    
    // -------------------------------------------
    //  Plugin Removal
    // -------------------------------------------
    
    function plugin_remove()
    {
        global $IN, $DSP, $LANG;
        
        $deleted = $IN->GBL('deleted', 'POST');
        $message = '';
        $style = '';
        $i = 0;
        
        $DSP->title  = $LANG->line('plugin_removal');
        $DSP->crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=plugin_manager', $LANG->line('plugin_manager')).
        $DSP->crumb  = $DSP->crumb_item($LANG->line('plugin_removal'));
        
        $r  = $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 

        $r .= $DSP->table('', '0', '10', '100%').
              $DSP->tr().
              $DSP->td('tableHeadingLargeBold', '', '').
              $LANG->line('plugin_removal_status').
              $DSP->td_c().
              $DSP->tr_c();
        
        foreach ( $deleted as $name )
        {
        		$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
        	
            if (unlink(PATH_PI.'pi.'.$name.'.php'))
				$message = $LANG->line('plugin_removal_success').' '.ucwords(str_replace("_", " ", $name));
			else
				$message = $LANG->line('plugin_removal_error').' '.ucwords(str_replace("_", " ", $name)).'.';
				
			$r .= $DSP->tr();
       		$r .= $DSP->table_qcell($style, $DSP->qdiv('highlight', $message), '100%');
        		$r .= $DSP->tr_c();
        }
        
        $r .= $DSP->table_c();
        
        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      

		$DSP->body = $r;
    }
    // END

    // -------------------------------------------
    //  SQL Manager
    // -------------------------------------------    

    function sql_info()
    {
        global $DB, $DSP, $PREFS, $LOC, $LANG;
        
		$i = 0;
		$style_one 	= 'tableCellOne';
		$style_two 	= 'tableCellTwo';
			
        
        $query = $DB->query("SELECT version() AS ver");
		
        $DSP->title = $LANG->line('utilities');
        $DSP->crumb = $LANG->line('utilities');
                                
        $DSP->body = $DSP->heading($LANG->line('sql_manager'));
        
		// -----------------------------
    	//  Table Header
		// -----------------------------   		

        $DSP->body	.=	$DSP->table('tableBorder', '0', '0', '100%').
					  	$DSP->tr().
					  	$DSP->td('tablePad'); 

        $DSP->body	.=	$DSP->table('', '0', '0', '100%').
						$DSP->tr().
						$DSP->table_qcell('tableHeadingBold', 
											array(
													$LANG->line('sql_info'),
													$LANG->line('value')
												 )
											).
						$DSP->tr_c();
						
					
		// -------------------------------------------
		//  Database Type
		// -------------------------------------------    
  		
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('database_type')),
												$PREFS->ini('db_type')
											  )
										);			
  		
  		
  		
		// -------------------------------------------
		//  SQL Version
		// -------------------------------------------    
  		
		$query = $DB->query("SELECT version() AS ver");
				
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('sql_version')),
												$query->row['ver']
											  )
										);	
										
										

        $DB->fetch_fields = TRUE;

        $query = $DB->query("SHOW TABLE STATUS FROM `".$PREFS->ini('db_name')."`");

		$totsize = 0;
		$records = 0;
		
        foreach ($query->result as $val)
        {
            if ( ! ereg("^$DB->prefix", $val['Name']))
            {
                continue;
            }
                                
            $totsize += ($val['Data_length'] + $val['Index_length']);
            $records += $val['Rows'];
        }

			
		// -------------------------------------------
		//  Database Records
		// -------------------------------------------    
						
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('records')),
												$records
											  )
										);
			
		// -------------------------------------------
		//  Database Size
		// -------------------------------------------    

        $size = Utilities::byte_format($totsize);
        
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('database_size')),
												$size['0'].' '.$size['1']
											  )
										);

			
		// -------------------------------------------
		//  Database Uptime
		// -------------------------------------------    
			
        $query = $DB->query("SHOW STATUS");
		
		$uptime  = '';
		$queries = '';
				
		foreach ($query->result as $key => $val)
		{
            foreach ($val as $v)
            {
				if (eregi("^uptime", $v))
				{
					$uptime = $key;
				}
				
				if (eregi("^questions", $v))
				{
					$queries = $key;
				}
			}		
		}    
		
				                   
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('database_uptime')),
												$LOC->format_timespan($query->result[$uptime]['Value'])
											  )
										);			
       						
		// -------------------------------------------
		//  Total Queries
		// -------------------------------------------    
       						
       
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $LANG->line('total_queries')),
												number_format($query->result[$queries]['Value'])
											  )
										);			

        $DSP->body	.=	$DSP->table_c(); 

        $DSP->body	.=	$DSP->td_c().  
						$DSP->tr_c().     
						$DSP->table_c();  

        
		// -------------------------------------------
		//  SQL Utilities
		// -------------------------------------------    
       				
		$DSP->body	.=	$DSP->qdiv('', NBS);

        $DSP->body	.=	$DSP->table('tableBorder', '0', '0', '100%').
					  	$DSP->tr().
					  	$DSP->td('tablePad'); 

        $DSP->body	.=	$DSP->table('', '0', '0', '100%').
						$DSP->tr().
						$DSP->table_qcell('tableHeadingBold', 
											array(
													$LANG->line('sql_utilities'),
												 )
											).
						$DSP->tr_c();
						

		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=view_database', $LANG->line('view_database')))
											  )
										);			
       						
       
		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_backup', $LANG->line('sql_backup')))
											  )
										);			

		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_query', $LANG->line('sql_query')))
											  )
										);			


		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_status', $LANG->line('sql_status')))
											  )
										);			


		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_sysvars', $LANG->line('sql_system_vars')))
											  )
										);			

		$DSP->body	.=	$DSP->table_qrow( ($i++ % 2) ? $style_one : $style_two, 
										array(
												$DSP->qspan('defaultBold', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_plist', $LANG->line('sql_processlist')))
											  )
										);			

        $DSP->body	.=	$DSP->table_c(); 

        $DSP->body	.=	$DSP->td_c().  
						$DSP->tr_c().     
						$DSP->table_c();
    }
    // END
 
  
    
    // -------------------------------------------
    //  SQL Manager
    // -------------------------------------------    

    function sql_manager($process = '', $return = FALSE)
    {  
        global $DSP, $IN, $DB, $REGX, $SESS, $LANG, $PREFS;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        // We use this conditional only for demo installs.
        // It prevents users from using the SQL manager

		if ($PREFS->ini('demo_date') != FALSE)
		{
            return $DSP->no_access_message();
		}
                
        $run_query = FALSE;
        $row_limit = 100;
        $paginate  = '';

        
        // Set the "fetch fields" flag to true so that
        // the Query function will return the field names
        
        $DB->fetch_fields = TRUE;

        switch($process)
        {
            case 'plist'    : 
                                $query  = $DB->query("SHOW PROCESSLIST");
                                $title  = $LANG->line('sql_processlist');
                                $crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_manager', $LANG->line('sql_manager'));
                                $crumb .= $DSP->crumb_item($LANG->line('sql_processlist'));
                break;
            case 'sysvars'  : 
                                $query	= $DB->query("SHOW VARIABLES");
                                $title	= $LANG->line('sql_system_vars');
                                $crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_manager', $LANG->line('sql_manager'));
                                $crumb .= $DSP->crumb_item($LANG->line('sql_system_vars'));
                break;
            case 'status'    : 
                                $query 	= $DB->query("SHOW STATUS"); 
                                $title 	= $LANG->line('sql_status');
                                $crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_manager', $LANG->line('sql_manager'));
                                $crumb .= $DSP->crumb_item($LANG->line('sql_status'));
                break;
            case 'run_query' : 
                                $run_query = TRUE;
                                $title	= $LANG->line('query_result');
								$crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_manager', $LANG->line('sql_manager'));
                                $crumb .= $DSP->crumb_item($DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_query', $LANG->line('sql_query')));
                                $crumb .= $DSP->crumb_item($LANG->line('query_result'));
                break;
            default           : return;
                break;
        }

    
        // Fetch the query.  It can either come from a
        // POST request or a url encoded GET request
        
        if ($run_query == TRUE)
        {            
            if ( ! $sql = stripslashes($IN->GBL('thequery', 'POST')))
            {
                if ( ! $sql = $IN->GBL('thequery', 'GET'))
                {
                    return Utilities::sql_query_form();
                }
                else
                {
                    $sql = urldecode($sql);
                }
            }
                                    
            $sql = str_replace(";", "", $sql);
                        
                        
            // Determine if the query is one of the non-allowed types
    
            $qtypes = array('CREATE', 'DROP', 'FLUSH', 'REPLACE', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK');
                    
            foreach ($qtypes as $type)
            {
                if (stristr($sql, $type))
                {            
                    return $DSP->error_message($LANG->line('sql_not_allowed'));
                }
            }
            
            // If it's a DELETE query, require that a Super Admin be the one submitting it
            
            if (eregi("DELETE", $sql) || eregi('ALTER', $sql))
            {
				if ($SESS->userdata['group_id'] != '1')
				{
					return $DSP->no_access_message();
				}
            }
            
            
            // If it's a SELECT query we'll see if we need to limit
            // the result total and add pagenation links
            
            if (stristr($sql, 'SELECT'))
            {
                if ( ! preg_match("/LIMIT\s+[0-9]/i", $sql))
                {
                    $result = $DB->query($sql); 
                    
                     if ($result->num_rows > $row_limit)
                     { 
                        $row = ( ! $IN->GBL('ROW')) ? 0 : $IN->GBL('ROW');
                        
                        $url = BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=run_query'.AMP.'thequery='.urlencode($sql);
                     
                        $paginate = $DSP->pager(  $url,
                                                  $result->num_rows, 
                                                  $row_limit,
                                                  $row,
                                                  'ROW'
                                                );
                         
                        $sql .= " LIMIT ".$row.", ".$row_limit;
                     }
                }
            }
                            
            $query = $DB->query($sql); 
            
            $qtypes = array('INSERT', 'UPDATE', 'DELETE', 'ALTER');

            $write = FALSE;
            
            foreach ($qtypes as $type)
            {
                if (eregi("^$type", $sql))
                {
                    $write = TRUE;
                }
            }
            
            if ($write == TRUE)
            {
                if ($DB->affected_rows > 0)
                {
                   return $DSP->set_return_data( $title, 
                                                    
                                                 $DSP->heading($title).
                                                 $DSP->qdiv('success', $LANG->line('sql_good_result')).
                                                 $DSP->qdiv('', $LANG->line('total_affected_rows').NBS.$DB->affected_rows),
                                                 $crumb
                                                ); 
                }
            }            
        }
                
        // No result?  All that effort for nothing?
       
        if ($query->num_rows == 0)
        {
           return $DSP->set_return_data( $title, 
                                            
                                         $DSP->heading($title).
                                         $DSP->qdiv('highlight', $LANG->line('sql_no_result')),
                
                                         $crumb
                                        ); 
        }

        
        // Build the output
        
        $r =  $DSP->heading($title);
        
        $r .= $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 

        $r .= $DSP->table('', '0', '10', '100%')
             .$DSP->tr();
        
        foreach ($query->fields as $f)
        {
            $r .= $DSP->td('tableHeadingBold').$f.$DSP->td_c();
        }
        
        $r .= $DSP->tr_c();

        // Build our table rows

        $i = 0;
                        
        foreach ($query->result as $key => $val)
        {
            $style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
        
            $r .= $DSP->tr();
            
            foreach ($val as $k => $v)
            {
                $r .= $DSP->td($style).htmlspecialchars($v).$DSP->td_c();
            }
            
            $r .= $DSP->tr_c();
        }
                
        $r.= $DSP->table_c();
        
        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      

        if ($paginate != '')
        {
            $r .= $DSP->qdiv('', $paginate);
        }


		if ($return == FALSE)
		{	
			$DSP->title = &$title;
			$DSP->crumb = &$crumb;
			$DSP->body  = &$r;
		}
		else
		{
			return $r;
		}                                            
    }
    // END    
    
    


    // -------------------------------------------
    //   Delete cache file form
    // -------------------------------------------    

    function clear_cache_form($message = FALSE)
    {  
        global $DSP, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
                
        $DSP->title = $LANG->line('clear_caching');
        $DSP->crumb = $LANG->line('clear_caching');    
        
        
        if ($message == TRUE)
        {
            $DSP->body  = $DSP->qdiv('success', $LANG->line('cache_deleted'));                            
        }

		$DSP->body .= $DSP->div('box320');
        $DSP->body .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=clear_caching');
        $DSP->body .= $DSP->heading($LANG->line('clear_caching'));        
        
        $DSP->body .= $DSP->div('itemWrapper');
        
        if ( ! isset($_POST['type']))
        {
            $_POST['type'] = 'page';         
        }        
        
        $selected = ($_POST['type'] == 'page') ? 1 : '';

        $DSP->body .= $DSP->input_radio('type', 'page', $selected).$LANG->line('page_caching').BR; 
        
        $selected = ($_POST['type'] == 'tag') ? 1 : '';
        
        $DSP->body .= $DSP->input_radio('type', 'tag', $selected).$LANG->line('tag_caching').BR;
    
        $selected = ($_POST['type'] == 'db') ? 1 : '';

        $DSP->body .= $DSP->input_radio('type', 'db', $selected).$LANG->line('db_caching').BR;
        
        $selected = ($_POST['type'] == 'all') ? 1 : '';

        $DSP->body .= $DSP->input_radio('type', 'all', $selected).$LANG->line('all_caching');
        
        $DSP->body .= $DSP->div_c();
        $DSP->body .= $DSP->qdiv('itemWrapper', BR.$DSP->input_submit($LANG->line('submit')));
        $DSP->body .= $DSP->form_c();
        $DSP->body .= $DSP->div_c();
    }
    // END
    
 
 
    // -------------------------------------------
    //   Delete cache files
    // -------------------------------------------    

    function clear_caching()
    {  
        global $FNS;
 
        $FNS->clear_caching($_POST['type']);
        
        return Utilities::clear_cache_form(TRUE);
    }
    // END 
 
 
    // -------------------------------------------
    //   SQL backup form
    // -------------------------------------------    

    function sql_backup()
    {  
        global $DSP, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        ob_start();
    
        ?>
        <script language="javascript" type="text/javascript"> 
        <!--

            function setType()
            {
                document.forms[0].type[0].checked = true;
            }
        
        //-->
        </script>
        <?php
    
        $buffer = ob_get_contents();
                
        ob_end_clean(); 
        
        $DSP->title  = $LANG->line('sql_backup');
		$DSP->crumb  = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=sql_manager', $LANG->line('sql_manager'));
		$DSP->crumb .= $DSP->crumb_item($LANG->line('sql_backup'));
        
        $DSP->body  = $buffer;                            

        $DSP->body .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=do_sql_backup');
        $DSP->body .= $DSP->heading($LANG->line('sql_backup'));        
        
        $DSP->body .= $DSP->div('itemWrapper');
        
        $DSP->body .= $LANG->line('backup_info').BR.BR;

        $DSP->body .= $DSP->input_radio('file', 'y', 1).$LANG->line('save_as_file').BR; 
        $DSP->body .= $DSP->input_radio('file', 'n', '', " onclick=\"setType();\"").$LANG->line('view_in_browser').BR.BR;  
              
        $DSP->body .= $DSP->input_radio('type', 'text', 1).$LANG->line('plain_text').BR;
        $DSP->body .= $DSP->input_radio('type', 'zip').$LANG->line('zip').BR;
        $DSP->body .= $DSP->input_radio('type', 'gzip').$LANG->line('gzip');
        
        $DSP->body .= $DSP->div_c();
        $DSP->body .= $DSP->qdiv('itemWrapper', BR.$DSP->input_submit($LANG->line('submit')));
        $DSP->body .= $DSP->form_c();
    }
    // END
    
    
    
    
    // -------------------------------------------
    //   Do SQL backup
    // -------------------------------------------    

    function do_sql_backup($type = '')
    {  
        global $IN, $DSP, $DB, $LANG, $LOC;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        // Names of tables we do not want the data backed up from
        
        $ignore = array('exp_security_hashes ', 'exp_sessions');
        
        // ---------------------------------------------------------
        //  Are we backing up the full database or separate tables?
        // ---------------------------------------------------------

        if ($type == '')
        {
            $type = $_POST['type'];
            
            $file = ($IN->GBL('file', 'POST') == 'y') ? TRUE : FALSE;
        }
        else
        {
            switch ($_POST['table_action'])
            {
                case 'BACKUP_F' : $type = 'text'; $file = TRUE;
                    break;
                case 'BACKUP_Z' : $type = 'zip';  $file = TRUE;
                    break;
                case 'BACKUP_G' : $type = 'gzip'; $file = TRUE;
                    break;
                default         : $type = 'text'; $file = FALSE;
                    break;
            }
        }

        
        // ------------------------------------------------------------
        //  Build the output headers only if we are downloading a file
        // ------------------------------------------------------------
        
        ob_start();

        if ($file)
        {
            // Assign the name of the of the backup file
            
            $now = $LOC->set_localized_time();
            
            $filename = $DB->database.'_'.date('y', $now).date('m', $now).date('d', $now);
        
        
            switch ($type)
            {
                case 'zip' :
                
                            if ( ! @function_exists('gzcompress')) 
                            {
                                return $DSP->error_message($LANG->line('unsupported_compression'));
                            }
                
                            $ext  = 'zip';
                            $mime = 'application/x-zip';
                                    
                    break;
                case 'gzip' :
                
                            if ( ! @function_exists('gzencode')) 
                            {
                                return $DSP->error_message($LANG->line('unsupported_compression'));
                            }
                
                            $ext  = 'gz';
                            $mime = 'application/x-gzip';
                    break;
                default     :
                
                            $ext = 'sql';
                            
                            if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE") || strstr($_SERVER['HTTP_USER_AGENT'], "OPERA")) 
                            {
                                $mime = 'application/octetstream';
                            }
                            else
                            {
                                $mime = 'application/octet-stream';
                            }
                
                    break;
            }
            
            if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE"))
            {
                header('Content-Type: '.$mime);
                header('Content-Disposition: inline; filename="'.$filename.'.'.$ext.'"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
            } 
            else 
            {
                header('Content-Type: '.$mime);
                header('Content-Disposition: attachment; filename="'.$filename.'.'.$ext.'"');
                header('Expires: 0');
                header('Pragma: no-cache');
            }
        }
        else
        {
            echo $DSP->heading($LANG->line('sql_backup'));
            
            echo '<pre>';
        }                 

        // -------------------------------------------
        //  Fetch the table names
        // -------------------------------------------    
        
        $DB->fetch_fields = TRUE;
        
        // Individual tables
        
        if (isset($_POST['table_action'])) 
        {
            foreach ($_POST['table'] as $key => $val)
            {
                $tables[] = $key;    
            }
        }
        
        // the full database
        
        else
        {
            $tables = $DB->fetch_tables();
        }
        
        $i = 0;
        
        foreach ($tables as $table)
        { 
            // -------------------------------------------
            //  Fetch the table structure
            // -------------------------------------------    

            echo NL.NL.'#'.NL.'# TABLE STRUCTURE FOR: '.$table.NL.'#'.NL.NL;
                
            echo 'DROP TABLE IF EXISTS '.$table.';'.NL.NL;
        
			$query = $DB->query("SHOW CREATE TABLE `".$DB->database.'`.'.$table);
			
			foreach ($query->result['0'] as $val)
			{
			    if ($i++ % 2)
			    {   
			    	$val = str_replace('`', '', $val).NL.NL;
			    	$val = preg_replace('/CREATE(.*\))/s', "CREATE\\1;", $val);
			    	$val = str_replace('TYPE=MyISAM', '',	$val);
			    	
			    	echo $val;
			    }
			}
			
			
			if ( ! in_array($table, $ignore))
			{
                // -------------------------------------------
                //  Fetch the data in the table
                // -------------------------------------------    
                
                $query = $DB->query("SELECT * FROM $table");
                
                if ($query->num_rows == 0)
                {
                    continue;
                }
                
                // -------------------------------------------
                //  Assign the field name
                // -------------------------------------------    
                
                $fields = '';
                
                foreach ($query->fields as $f)
                {
                    $fields .= $f . ', ';            
                }
            
                $fields = preg_replace( "/, $/" , "" , $fields);
                
                // -------------------------------------------
                //  Assign the value in each field
                // -------------------------------------------    
                                         
                foreach ($query->result as $val)
                {
                    $values = '';
                
                    foreach ($val as $v)
                    {
                        $v = str_replace(array("\x00", "\x0a", "\x0d", "\x1a"), array('\0', '\n', '\r', '\Z'), $v);   
                        $v = str_replace(array("\n", "\r", "\t"), array('\n', '\r', '\t'), $v);   
                        $v = str_replace('\\', '\\\\',	$v);
                        $v = str_replace('\'', '\\\'',	$v);
                        $v = str_replace('\\\n', '\n',	$v);
                        $v = str_replace('\\\r', '\r',	$v);
                        $v = str_replace('\\\t', '\t',	$v);

						$values .= "'".$v."'".', ';
                    }
                    
                    $values = preg_replace( "/, $/" , "" , $values);
                    
                    if ($file == FALSE)
                    {
                        $values = htmlspecialchars($values);
                    }
                    
                    // Build the INSERT string
        
                    echo 'INSERT INTO '.$table.' ('.$fields.') VALUES ('.$values.');'.NL;
                }
            }
        }
        // END WHILE LOOP
        
        
        if ($file == FALSE)
        {
            echo '</pre>';
        }


        $buffer = ob_get_contents();
        
        ob_end_clean(); 
        
        
        // -------------------------------------------
        //  Create the selected output file
        // -------------------------------------------    
        
        if ($file)
        {
            switch ($type)
            {
                case 'zip' :  
                              $zip = new Zipper;
                                
                              $zip->add_file($buffer, $filename.'.sql');
                                
                              echo $zip->output_zipfile();                
                    break;
                case 'gzip' : echo gzencode($buffer);
                    break;
                 default    : echo $buffer;
                    break;
            }
            
            exit;
        }
        else
        {
            $DSP->title = $LANG->line('utilities');
            $DSP->crumb = $LANG->line('utilities');
            $DSP->body = &$buffer;
        }        
    }
    // END
    

  
    // -------------------------------------------
    //   SQL tables
    // -------------------------------------------    

    function view_database()
    {  
        global $DSP, $DB, $PREFS, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $DB->fetch_fields = TRUE;

        $query = $DB->query("SHOW TABLE STATUS FROM `".$PREFS->ini('db_name')."`");
        
        // Build the output
        
        $r = Utilities::toggle_code();
        
        $r .= $DSP->heading($LANG->line('view_database'));
        
        $r .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=table_action', 'tables');
        
        $r .= $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 
        
        $r .= $DSP->table('', '0', '10', '100%')
             .$DSP->tr()                            
             .$DSP->td('tableHeadingBold', '4%').$DSP->input_checkbox('toggleflag', '', '', "onclick=\"toggle(this);\"").$DSP->td_c()
             .$DSP->td('tableHeadingBold', '36%').$LANG->line('table_name').$DSP->td_c()
             .$DSP->td('tableHeadingBold', '15%').$LANG->line('browse').$DSP->td_c()
             .$DSP->td('tableHeadingBold', '15%').$LANG->line('records').$DSP->td_c()
             .$DSP->td('tableHeadingBold', '15%').$LANG->line('size').$DSP->td_c()
             .$DSP->tr_c();

        // Build our table rows

        $i = 0;
        $records = 0;
        $tables  = 0;
        $totsize = 0;
                                
        foreach ($query->result as $val)
        {
            if ( ! ereg("^$DB->prefix", $val['Name']))
            {
                continue;
            }
        
            $style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
            
            $len  = $val['Data_length'] + $val['Index_length'];
            
            $size = Utilities::byte_format($len, 3);
                                
            $r .= $DSP->tr()            
                 .$DSP->td($style, '4%')."<input type='checkbox' name=\"table[".$val['Name']."]\" value='y' />".$DSP->td_c()
                 .$DSP->td($style, '36%').'<b>'.$val['Name'].'</b>'.$DSP->td_c()
                 .$DSP->td($style, '15%')                 
                 .$DSP->anchor(
                                BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=run_query'.AMP.'thequery='.urlencode("SELECT * FROM ".$val['Name']),
                                $LANG->line('browse')
                            )           
                 .$DSP->td_c()
                 .$DSP->td($style, '15%').$val['Rows'].$DSP->td_c()
                 .$DSP->td($style, '15%').$size['0'].' '.$size['1'].$DSP->td_c()
                 .$DSP->tr_c();
                  
            $records += $val['Rows'];
            $totsize += $len;
            $tables++;
        }

        $size = Utilities::byte_format($totsize);
    
        $style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';

        $r .= $DSP->tr()
             .$DSP->td($style).NBS.$DSP->td_c()
             .$DSP->td($style).'<b>'.$tables.NBS.$LANG->line('tables').'</b>'.$DSP->td_c()
             .$DSP->td($style).NBS.$DSP->td_c()
             .$DSP->td($style).'<b>'.$records.'</b>'.$DSP->td_c()
             .$DSP->td($style).'<b>'.$size['0'].' '.$size['1'].'</b>'.$DSP->td_c()
             .$DSP->tr_c();
                
    
        $style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';

        $r .= $DSP->tr()
             .$DSP->td($style, '', '1')
             .$DSP->input_checkbox('toggleflag', '', '', "onclick=\"toggle(this);\"")
             .$DSP->td_c()
             .$DSP->td($style, '', '1')
             .$LANG->line('select_all')
             .$DSP->td_c();
             
        $r .= $DSP->td($style, '', '4')
             .$DSP->input_select_header('table_action')
             .$DSP->input_select_option('OPTIMIZE', $LANG->line('optimize_table'))
             .$DSP->input_select_option('REPAIR',   $LANG->line('repair_table'))
             .$DSP->input_select_option('BACKUP_V', $LANG->line('view_table_sql'))
             .$DSP->input_select_option('BACKUP_F', $LANG->line('backup_tables_file'))
             .$DSP->input_select_option('BACKUP_Z', $LANG->line('backup_tables_zip'))
             .$DSP->input_select_option('BACKUP_G', $LANG->line('backup_tables_gzip'))
             .$DSP->input_select_footer()
             .$DSP->input_submit($LANG->line('submit'))
             .$DSP->td_c()
             .$DSP->tr_c()
             .$DSP->table_c();

        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      

        $r .= $DSP->form_c();
        
        $DSP->title = $LANG->line('view_database');
        $DSP->crumb = $LANG->line('view_database');
        $DSP->body  = &$r;
    }
    // END
    
    

    // -------------------------------------------
    //   JavaScript toggle code
    // -------------------------------------------    

    function toggle_code()
    {
        ob_start();
    
        ?>
        <script language="javascript" type="text/javascript"> 
        <!--
    
        function toggle(thebutton)
        {
            if (thebutton.checked) 
            {
               val = true;
            }
            else
            {
               val = false;
            }
                        
            var len = document.tables.elements.length;
        
            for (var i = 0; i < len; i++) 
            {
                var button = document.tables.elements[i];
                
                var name_array = button.name.split("["); 
                
                if (name_array[0] == "table") 
                {
                    button.checked = val;
                }
            }
            
            document.tables.toggleflag.checked = val;
        }
        
        //-->
        </script>
        <?php
    
        $buffer = ob_get_contents();
                
        ob_end_clean(); 
        
        return $buffer;
    } 
    // END 
   


    // ----------------------------------
    //   Number format
    // ----------------------------------  
  
    function byte_format($num)
    {
        if ($num >= 1000000000) 
        {
            $num = round($num/107374182)/10;
            $unit  = 'GB';
        }
        elseif ($num >= 1000000) 
        {
            $num = round($num/104857)/10;
            $unit  = 'MB';
        }
        elseif ($num >= 1000) 
        {
            $num = round($num/102)/10;
            $unit  = 'KB';
        }
        else
        {
            $unit = 'Bytes';
        }

        return array(number_format($num, 1), $unit);
    }
    // END



    // -------------------------------------------
    //   Run table action (repair/optimize)
    // -------------------------------------------    

    function run_table_action()
    {
        global $DSP, $DB, $PREFS, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        if ( ! isset($_POST['table']))
        {
            return $DSP->error_message($LANG->line('no_buttons_selected'));
        }
        
        $action = array('OPTIMIZE', 'REPAIR');

        if ( ! in_array($_POST['table_action'], $action))
        {
            return Utilities::do_sql_backup($_POST['table_action']);
        }
        
        $title = $LANG->line(strtolower($_POST['table_action']));
        
        $r  = $DSP->heading($title);
        
        $r .= $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 
        
        $r .= $DSP->table('', '0', '10', '100%');
        $r .= $DSP->tr();
        
        
        $DB->fetch_fields = TRUE;
                        
        $query = $DB->query("ANALYZE TABLE exp_members");

        foreach ($query->fields as $f)
        {
            $r .= $DSP->td('tableHeadingBold').$f.$DSP->td_c();
        }
            
        $r .= $DSP->tr_c();
        
        $i = 0;
        
        foreach ($_POST['table'] as $key => $val)
        {                    
            $sql = $_POST['table_action']." TABLE ".$key;
            
            $query = $DB->query($sql);

            foreach ($query->result as $key => $val)
            {
                $style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
            
                $r .= $DSP->tr();
                
                foreach ($val as $k => $v)
                {
                    $r .= $DSP->td($style).$v.$DSP->td_c();
                }
                
                $r .= $DSP->tr_c();
            }
                    
        }
        
        $r.= $DSP->table_c();

        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      

       // Set the return data

        $DSP->title = $LANG->line('utilities').$DSP->crumb_item($title);
        $DSP->crumb = $LANG->line('utilities').$DSP->crumb_item($title);
        $DSP->body  = &$r;                                                  
    }
    // END

    
    
    // -------------------------------------------
    //   SQL query form
    // -------------------------------------------    

    function sql_query_form()
    {  
        global $DSP, $LANG, $SESS;
        
        if ($SESS->userdata['group_id'] != '1')
        {
            return $DSP->no_access_message();
        }
        
        $DSP->title = $LANG->line('utilities');
        $DSP->crumb = $LANG->line('sql_query');                             
                                      
        $DSP->body =  $DSP->heading($LANG->line('sql_query'))          
                     .$DSP->qdiv('itemWrapper', $LANG->line('sql_query_instructions'))
                     .$DSP->qdiv('itemWrapper', '<b>'.$LANG->line('advanced_users_only').'</b>')
                     .$DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=run_query')
                     .$DSP->input_textarea('thequery', '', '5', 'textarea', '80%')
                     .$DSP->br(2)
                     .$DSP->input_submit($LANG->line('submit'), 'submit')
                     .$DSP->form_c();    
    }
    // END
    
    
   
    // -------------------------------------------
    //   Search and Replace form
    // -------------------------------------------    

    function search_and_replace_form()
    {  
        global $DSP, $DB, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        
        // Select menu of available fields where a replacement can occur.
        
        $r  = $DSP->input_select_header('fieldname');
        $r .= $DSP->input_select_option('', '--');
        $r .= $DSP->input_select_option('title', $LANG->line('weblog_entry_title'));
        $r .= $DSP->input_select_option('', '--');
        $r .= $DSP->input_select_option('', $LANG->line('weblog_fields'));
       
        // Fetch the weblog fields
        
        $sql = "SELECT exp_field_groups.group_id, exp_field_groups.group_name 
        		FROM exp_weblogs, exp_field_groups
        		WHERE exp_weblogs.field_group = exp_field_groups.group_id
        		AND exp_weblogs.is_user_blog = 'n'";
        
		$query = $DB->query($sql);
		
		$fg_array = array();
		
		$sql = "SELECT group_id, field_id, field_label FROM  exp_weblog_fields WHERE";
        
		foreach ($query->result as $row)
		{
			$sql .= " group_id = '".$row['group_id']."' OR";
		
			$fg_array[$row['group_id']] = $row['group_name'];
		}     
		
		$sql = substr($sql, 0, -2);
		
		$sql .= " ORDER BY group_id, field_label";
        
                
        $query = $DB->query($sql);
        
        foreach ($query->result as $row)
        {
            $r .= $DSP->input_select_option('field_id_'.$row['field_id'], NBS.NBS.NBS.NBS.NBS.NBS.$row['field_label'].' ('.$fg_array[$row['group_id']].')');
        }
        
        $r .= $DSP->input_select_option('', '--');
        
        $r .= $DSP->input_select_option('template_data', $LANG->line('templates'));
        
        $r .= $DSP->input_select_option('', '--');
        
        $LANG->fetch_language_file('templates');
        
        $r .= $DSP->input_select_option('template_data', $LANG->line('template_groups'));
        
        $query = $DB->query("SELECT group_id, group_name FROM exp_template_groups");
        
        foreach($query->result as $row)
        {
        		$r .= $DSP->input_select_option('template_'.$row['group_id'], NBS.NBS.NBS.NBS.NBS.NBS.$row['group_name']);
        }
        
        
        
        
        $r .= $DSP->input_select_footer();
        
        $DSP->title = $LANG->line('utilities');
        $DSP->crumb = $LANG->line('search_and_replace');                             
                                      
        $DSP->body =	$DSP->div('box').
					$DSP->heading($LANG->line('search_and_replace')).                    
					$DSP->qdiv('itemWrapper',$LANG->line('sandr_instructions')).
					$DSP->div('itemWrapper').
					$DSP->qspan('alert', $LANG->line('advanced_users_only')).
					$DSP->div_c().
					
					$DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=run_sandr').
					
					$DSP->qdiv('itemWrapper', $DSP->qspan('defaultBold', BR.$LANG->line('search_term'))).
					
					$DSP->input_text('searchterm', '', '60', '120', 'input', '500px').
									
					$DSP->qdiv('itemWrapper', $DSP->qspan('defaultBold', BR.$LANG->line('replace_term'))).
					
					$DSP->input_text('replaceterm', '', '60', '120', 'input', '500px').
					
					$DSP->qdiv('itemWrapper', $DSP->qspan('defaultBold', BR.$LANG->line('replace_where'))).
					
					$r.
					
					$DSP->qdiv('alert', BR.$LANG->line('be_careful').NBS.NBS.$LANG->line('action_can_not_be_undone')).
					
					$DSP->qdiv('defaultBold', BR.$LANG->line('search_replace_disclaimer').BR.BR).
					
					$DSP->input_submit($LANG->line('submit'), 'submit').
					$DSP->form_c().
					$DSP->div_c();  
    }
    // END
   
  
  
    // -------------------------------------------
    //  Search and replace
    // -------------------------------------------    

    function search_and_replace()
    {  
        global $DSP, $IN, $DB, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
  
           $search  = $IN->GBL('searchterm', 'POST');
           $replace = $IN->GBL('replaceterm', 'POST');
           $field   = $IN->GBL('fieldname', 'POST');
           

        if ( ! $search  || ! $replace || ! $field)
        {
           return Utilities::search_and_replace_form();
        }
        
        if ($field == 'title')
        {
        	$sql = "UPDATE `exp_weblog_titles` SET `$field` = REPLACE($field, '$search', '$replace')";
        }
        elseif ($field == 'template_data')
        {
        	$sql = "UPDATE `exp_templates` SET `$field` = REPLACE($field, '$search', '$replace') WHERE";
        
			$query = $DB->query("SELECT group_id FROM exp_template_groups WHERE is_user_blog = 'n'");
			
			foreach ($query->result as $row)
			{
				$sql .= " group_id = '".$row['group_id']."' OR";
			}
			
			$sql = substr($sql, 0, -2);        
        }
        elseif(eregi('^template_', $field))
        {
        	$sql = "UPDATE `exp_templates` SET `template_data` = REPLACE(template_data, '$search', '$replace') 
        			WHERE group_id = '".substr($field,9)."'";
        }
        else
        {
        	$sql = "UPDATE `exp_weblog_data` SET `$field` = REPLACE($field, '$search', '$replace') WHERE";
        
			$query = $DB->query("SELECT weblog_id FROM exp_weblogs WHERE is_user_blog = 'n'");
			
			foreach ($query->result as $row)
			{
				$sql .= " weblog_id = '".$row['weblog_id']."' OR";
			}
			
			$sql = substr($sql, 0, -2);
		}
        
        
        $DB->query($sql);
        
        $DSP->set_return_data(
                                $LANG->line('utilities'),
                                
                                $DSP->heading($LANG->line('search_and_replace')).                    
                                $DSP->qdiv('', BR.$LANG->line('rows_replaced').NBS.NBS.$DB->affected_rows),

                                $LANG->line('search_and_replace')
                              ); 
    }
    // END   
        
    
    // -------------------------------------------
    //   Data pruning
    // ------------------------------------------- 
    
    // This function is not done...   

    function data_pruning()
    {  
        global $DSP, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
                
        $DSP->set_return_data(
                                $LANG->line('utilities'), 
                                                    
                                 $DSP->div('defaultPad')
                                .$LANG->line('data_pruning')
                                .$DSP->div_c(),
                                                    
                                $LANG->line('data_pruning')
                              );     
    }
    // END

   
    // -------------------------------------------
    //   Recalculate Statistics - Main Page
    // -------------------------------------------    

    function recount_statistics()
    {  
        global $DSP, $LANG, $DB, $PREFS;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $sources = array('exp_members', 'exp_weblog_titles');
        
        $DSP->title = $LANG->line('utilities');        
        $DSP->crumb = $LANG->line('utilities'); 
        $DSP->rcrumb = $DSP->qdiv('crumblinksR', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_prefs', $LANG->line('set_recount_prefs')));

        $r = $DSP->heading($LANG->line('recalculate'));
        
        $r .= $DSP->qdiv('itemWrapper', $LANG->line('recount_info'));   
        
        
        $r .= $DSP->table('tableBorder', '0', '0', '100%').
              $DSP->tr().
              $DSP->td('tablePad'); 

        $r .= $DSP->table('', '0', '', '100%').
              $DSP->tr().
              $DSP->table_qcell('tableHeadingBold', 
                                array(
                                        $LANG->line('source'),
                                        $LANG->line('records'),
                                        $LANG->line('action')
                                     )
                                ).
                $DSP->tr_c();
        
        $i = 0;

        foreach ($sources as $val)
        {
			$query = $DB->query("SELECT COUNT(*) AS count FROM $val");
		  
			$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
			
			$r .= $DSP->tr();
			
			// Table name
			$r .= $DSP->table_qcell($style, $DSP->qdiv('defaultBold', $LANG->line($val)), '20%');
	
			// Table rows
			$r .= $DSP->table_qcell($style, $query->row['count'], '20%');
					
			// Action
			$r .= $DSP->table_qcell($style, $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=do_recount'.AMP.'TBL='.$val, $LANG->line('do_recount')), '20%');  
        }          

		$style = ($i++ % 2) ? 'tableCellOne' : 'tableCellTwo';
		
		$r .= $DSP->tr();
		
		// Table name
		$r .= $DSP->table_qcell($style, $DSP->qdiv('defaultBold', $LANG->line('site_statistics')), '20%');

		// Table rows
		$r .= $DSP->table_qcell($style, '4', '20%');
				
		// Action
		$r .= $DSP->table_qcell($style, $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=do_stats_recount', $LANG->line('do_recount')), '20%');  

        $r .= $DSP->table_c();

        $r .= $DSP->td_c()   
             .$DSP->tr_c()      
             .$DSP->table_c();      
        
        
        $DSP->body = $r;
    }
    // END
    

    // -------------------------------------------
    //   Recount preferences form
    // -------------------------------------------    

    function recount_preferences_form()
    {  
        global $IN, $DSP, $LANG, $PREFS;

        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $recount_batch_total = $PREFS->ini('recount_batch_total');  
        
        $DSP->title = $LANG->line('utilities');        
        $DSP->crumb = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_stats', $LANG->line('recount_stats')).$DSP->crumb_item($recount_batch_total);

        $r = $DSP->heading($LANG->line('set_recount_prefs')); 
        
        if ($IN->GBL('U'))
        {
            $r .= $DSP->qdiv('itemWrapper', $DSP->qdiv('success', $LANG->line('preference_updated')));
        }
        
        $r .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=set_recount_prefs');
        
        $r .= $DSP->qdiv('itemWrapper', $DSP->qdiv('defaultBold', $LANG->line('recount_instructions')));
        
        $r .= $DSP->qdiv('box450', $LANG->line('recount_instructions_cont'));
                
        $r .= $DSP->input_text('recount_batch_total', $recount_batch_total, '7', '5', 'input', '60px');

        $r .= $DSP->qdiv('itemWrapperTop', $DSP->input_submit($LANG->line('update')));
                
        $r .= $DSP->form_c();
        
        $DSP->body = $r;      
    }
    // END

    
    
    // -------------------------------------------
    //   Update recount preferences
    // -------------------------------------------    

    function set_recount_prefs()
    {  
        global $IN, $LANG, $DSP, $PREFS;

        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $total = $IN->GBL('recount_batch_total', 'POST');
        
        if ($total == '' || ! is_numeric($total))
        {
            return Utilities::recount_preferences_form();
        }
        
        $this->update_config_file(array('recount_batch_total' => $total), BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_prefs'.AMP.'U=1');
    }
    // END


    // -------------------------------------------
    //   Do General Statistics Recount
    // -------------------------------------------    

    function do_stats_recount()
    {  
        global $DSP, $LANG, $STAT;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }

        $STAT->update_comment_stats();
        $STAT->update_member_stats();
        $STAT->update_weblog_stats();
        $STAT->update_trackback_stats();
        
        $DSP->title = $LANG->line('utilities');        
        $DSP->crumb = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_stats', $LANG->line('recalculate')).$DSP->crumb_item($LANG->line('recounting'));

		$DSP->body = $DSP->qdiv('success', $LANG->line('recount_completed'));
	
		$DSP->body .= $DSP->qdiv('itemWrapper', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_stats', $LANG->line('return_to_recount_overview')));         
	
	}
	// END





    // -------------------------------------------
    //   Do Statistics member/weblog recount
    // -------------------------------------------    

    function do_recount()
    {  
        global $IN, $DSP, $LANG, $DB, $PREFS;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
                
        if ( ! $table = $IN->GBL('TBL', 'GET'))
        {
            return false;
        }
        
        $sources = array('exp_members', 'exp_weblog_titles');
        
        if ( ! in_array($table, $sources))
        {
            return false;
        }
   
   		if ( ! isset($_GET['T']))
   		{
        	$num_rows = FALSE;
        }
        else
        {
        	$num_rows = $_GET['T'];
			settype($num_rows, 'integer');
        }
        
        $batch = $PREFS->ini('recount_batch_total');
       	
		if ($table == 'exp_members')
		{
			$query = $DB->query("SELECT COUNT(*) AS count FROM exp_members");
			
			$total_rows = $query->row['count'];
		
			if ($num_rows !== FALSE)
			{			
				$query = $DB->query("SELECT member_id FROM exp_members ORDER BY member_id LIMIT $num_rows, $batch");
				
				foreach ($query->result as $row)
				{
					$res = $DB->query("SELECT count(entry_id) AS count FROM exp_weblog_titles WHERE author_id = '".$row['member_id']."'");
					$total_entries = $res->row['count'];
				
					$res = $DB->query("SELECT count(comment_id) AS count FROM exp_comments WHERE author_id = '".$row['member_id']."'");
					$total_comments = $res->row['count'];
					
					$DB->query($DB->update_string('exp_members', array( 'total_entries' => $total_entries,'total_comments' => $total_comments), "member_id = '".$row['member_id']."'"));   
				}
			}
		}
		elseif ($table == 'exp_weblog_titles')
		{
			$query = $DB->query("SELECT COUNT(*) AS count FROM exp_weblog_titles");
			
			$total_rows = $query->row['count'];
		
			if ($num_rows !== FALSE)
			{			
				$query = $DB->query("SELECT entry_id FROM exp_weblog_titles ORDER BY entry_id LIMIT $num_rows, $batch");
				
				foreach ($query->result as $row)
				{
					$res = $DB->query("SELECT count(comment_id) AS count FROM exp_comments WHERE entry_id = '".$row['entry_id']."'");
					$comment_total = $res->row['count'];
					
					$res = $DB->query("SELECT count(trackback_id) AS count FROM exp_trackbacks WHERE entry_id = '".$row['entry_id']."'");
					$trackback_total = $res->row['count'];
				   
					$DB->query($DB->update_string('exp_weblog_titles', array( 'comment_total' => $comment_total,'trackback_total' => $trackback_total), "entry_id = '".$row['entry_id']."'"));   
				}
			}
		}


        $DSP->title = $LANG->line('utilities');        
        $DSP->crumb = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_stats', $LANG->line('recalculate')).$DSP->crumb_item($LANG->line('recounting'));

        $r = $DSP->heading($LANG->line('recalculate')); 
        
		if ($num_rows === FALSE)
			$total_done = 0;
		else
			$total_done = $num_rows + $batch;

        $r .= $DSP->qdiv('itemWrapper', $LANG->line('total_records').NBS.$total_rows);
        
        $r .= $DSP->qdiv('itemWRapper', $LANG->line('items_remaining').NBS.($total_rows - $total_done));


        if ($total_done >= $total_rows)
        {
            $r = $DSP->qdiv('success', $LANG->line('recount_completed'));
        
            $r .= $DSP->qdiv('itemWrapper', $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=recount_stats', $LANG->line('return_to_recount_overview')));         
        
        }
        else
        {
            $line = $LANG->line('click_to_recount');
        
        	$to = (($total_done + $batch) >= $total_rows) ? $total_rows : ($total_done + $batch);
        
            $line = str_replace("%x", $total_done, $line);
            $line = str_replace("%y", $to, $line);
            
            $r .= $DSP->qdiv('itemWrapper', $DSP->heading($DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=do_recount'.AMP.'TBL='.$table.AMP.'T='.$total_done, '<b>'.$line.'</b>'), 5)); 
        }
        

        $DSP->body = $r;
   }
   // END
   
   

    // -------------------------------------------
    //   Translation select page
    // -------------------------------------------    

    function translate_select($message = '')
    {  
        global $DSP, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
                
        $r  = $DSP->heading($LANG->line('translation_tool'));
        
        if ($message != '')
        {
            $r .= $DSP->qdiv('success', $message);  
        }
        
        if ( ! is_writeable(PATH.'translations/'))
        {
            $r .= $DSP->qdiv('alert', $LANG->line('translation_dir_unwritable'));
        
            $r .= $DSP->div();
            $r .= BR;
            $r .= $LANG->line('please_set_permissions');
            $r .= $DSP->br(2);
            $r .= '<b><i>translations</i></b>';
            $r .= BR;
            $r .= $DSP->div_c();
        }
        else
        {
            $r .= $DSP->heading($LANG->line('choose_translation_file'), 5);
            $r .= $DSP->div();
            $source_dir = PATH_LANG.'english/';
                
            if ($fp = @opendir($source_dir)) 
            { 
                while (false !== ($file = readdir($fp))) 
                { 
                    if ( eregi(".php$",  $file))
                    {
                    	if (substr($file, 0, 4) == 'lang')
                    	{
                        	$r .= $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=translate'.AMP.'F='.$file, $file);
                        	$r .= BR;                
                    	}
                    }
                } 
            } 
        }         
                
        $DSP->set_return_data(
                                $LANG->line('utilities'), 
                                                    
                                $r,
                                                    
                                $LANG->line('translation_tool')
                              );     
    }
    // END
    


    // -------------------------------------------
    //   Translate tool
    // -------------------------------------------    

    function translate()
    {  
        global $DSP, $IN, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $source_dir = PATH_LANG.'english/';
        
        $dest_dir = PATH.'translations/';
        
        $which = $_GET['F'];
          	
        // We do this for security reasons
		if (stristr($which, '../') !== false)
			return;
          	
        require $source_dir.$which;
        
        $M = $L;
        
        unset($L);
            
        if (file_exists($dest_dir.$which))
        {
            $writable = ( ! is_writeable($dest_dir.$which)) ? FALSE : TRUE;
        
            require $dest_dir.$which;
        }
        else
        {
            $writable = TRUE;    
        
            $L = $M;
        }
        
        $r  = $DSP->heading($LANG->line('translation_tool'));
        $r .= $DSP->form('C=admin'.AMP.'M=utilities'.AMP.'P=save_translation');
        $r .= $DSP->input_hidden('filename', $which);
        
                
        foreach ($M as $key => $val)
        {
            if ($key != '')
            {
                $trans = ( ! isset($L[$key])) ? '' : $L[$key];
                        
                $r .= $DSP->qdiv('itemWrapper', BR.stripslashes($val));
                
                $trans = str_replace("&", "&amp;", $trans);
                
                if (strlen($trans) < 125)
                {
                    $r .= "<input type='text' name='".$key."' value='".stripslashes($trans)."' size='90'  class='input' style='width:95%'><br />\n";
                }
                else
                {
                    $r .= "<textarea style='width:95%' name='".$key."'  cols='90' rows='5' class='textarea' >".stripslashes($trans)."</textarea>";                
                }
            }
        }
        
        $r .= $DSP->div();
        $r .= $DSP->br(2);
        $r .= $DSP->input_submit($LANG->line('save_changes'));        
        $r .= $DSP->div_c();
                
        $DSP->set_return_data(
                                $LANG->line('utilities'), 
                                                    
                                $r,
                                
                                $DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=trans_menu', $LANG->line('translation_tool')).$DSP->crumb_item($which)
                              );     
    }
    // END


    // -------------------------------------------
    //   Save translation
    // -------------------------------------------    

    function save_translation()
    {  
        global $DSP, $LANG;
        
        if ( ! $DSP->allowed_group('can_admin_utilities'))
        {
            return $DSP->no_access_message();
        }
        
        $dest_dir = PATH.'translations/';
        $filename = $_POST['filename'];
    
        unset($_POST['filename']);
    
    
        $str = '<?php'."\n".'$L = array('."\n\n\n";
    
        foreach ($_POST as $key => $val)
        {
            $val = str_replace("<",  "&lt;",   $val);
            $val = str_replace(">",  "&gt;",   $val);
            $val = str_replace("'",  "&#39;",  $val);
            $val = str_replace("\"", "&quot;", $val);
            $val = stripslashes($val);
        
            $str .= "\"$key\" =>\n\"$val\",\n\n";
        }
    
        $str .= "''=>''\n);\n?".">";
        
        $fp = @fopen($dest_dir.$filename, 'wb');    

        flock($fp, LOCK_EX);
        
        fwrite($fp, $str);
                
        flock($fp, LOCK_UN);
        
        fclose($fp);
        
        return Utilities::translate_select($LANG->line('file_saved'));        
    
    }
    // END
        
    
    
    // -------------------------------------------
    //    PHP INFO
    // -------------------------------------------   

    // The default PHP info page has a lot of HTML we don't want, 
    // plus it's gawd-awful looking, so we'll clean it up.
    // Hopefully this won't break if different versions/platforms render 
    // the default HTML differently    
    
    function php_info()
    {  
        global $DSP, $PREFS, $LANG;
        
        
        // We use this conditional only for demo installs.
        // It prevents users from viewing this function

		if ($PREFS->ini('demo_date') != FALSE)
		{
            return $DSP->no_access_message();
		}
        
        ob_start();
        
        phpinfo();
        
        $buffer = ob_get_contents();
        
        ob_end_clean();
        
        $output = (preg_match("/<body.*?".">(.*)<\/body>/is", $buffer, $match)) ? $match['1'] : $buffer;
        $output = preg_replace("/width\=\".*?\"/", "width=\"100%\"", $output);        
        $output = preg_replace("/<hr.*?>/", "<br />", $output); // <?
        $output = preg_replace("/<a href=\"http:\/\/www.php.net\/\">.*?<\/a>/", "", $output);
        $output = preg_replace("/<a href=\"http:\/\/www.zend.com\/\">.*?<\/a>/", "", $output);
        $output = preg_replace("/<a.*?<\/a>/", "", $output);// <?
        $output = preg_replace("/<th(.*?).*?".">/", "<th \\1 class=\"phpinfohead\">", $output); 
        $output = preg_replace("/<tr(.*?).*?".">/", "<tr \\1 class=\"phpinforow\">\n", $output);
        $output = preg_replace("/<td.*?".">/", "<td valign=\"top\" class=\"phpinfocell\">", $output);
        $output = preg_replace("/cellpadding=\".*?\"/", "cellpadding=\"5\"", $output);
        $output = preg_replace("/cellspacing=\".*?\"/", "", $output);
        $output = preg_replace("/<h2 align=\"center\">PHP License<\/h2>.*?<\/table>/si", "", $output);
        $output = preg_replace("/ align=\"center\"/", "", $output);
        $output = preg_replace("/<table(.*?)bgcolor=\".*?\">/", "\n\n<table\\1>", $output);
        $output = preg_replace("/<table(.*?)>/", "\n\n<table\\1 class=\"phpinfotable\" cellspacing=\"1\">", $output);
        $output = preg_replace("/<h2>PHP License.*?<\/table>/is", "", $output);
                
        $output = $DSP->br(2).$output;
                
        $DSP->set_return_data(
                                $LANG->line('php_info'), 
                                $output, 
                                $LANG->line('php_info')
                             );
    }
    // END
    
}
// END CLASS



// ---------------------------------------------
//  Zip compression class
// ---------------------------------------------
//
// This class is based on a library aquired at Zend:
// http://www.zend.com/codex.php?id=696&single=1


class Zipper {

    var $zdata  = array();
    var $cdir   = array();
    var $offset = 0;

    
    // -------------------------------------------
    //  Compress directories
    // -------------------------------------------  

    function add_dir($name)
    {
        $name =str_replace ("\\", "/", $name);
        
        $fd = "\x50\x4b\x03\x04\x0a\x00\x00\x00\x00\x00\x00\x00\x00\x00"    
              .pack("V", 0)
              .pack("V", 0)
              .pack("V", 0)
              .pack("v", strlen($name))
              .pack("v", 0)
              .$name;
        
        $this->cdata[] = $fd;
                
        $cd = "\x50\x4b\x01\x02\x00\x00\x0a\x00\x00\x00\x00\x00\x00\x00\x00\x00"
              .pack("V", 0)
              .pack("V", 0)
              .pack("V", 0)
              .pack("v", strlen ($name))
              .pack("v", 0)
              .pack("v", 0)
              .pack("v", 0)
              .pack("v", 0)
              .pack("V", 16)
              .pack("V", $this->offset)
              .$name;
        
        $this->offset = strlen(implode('', $this->cdata));
        
        $this->cdir[] = $cd;
    }
    // END


    // -------------------------------------------
    //  Compress files
    // -------------------------------------------  

    function add_file($data, $name)
    {
        $name = str_replace("\\", "/", $name);
        
        $u_len = strlen($data);
        $crc   = crc32($data);
        $data  = gzcompress($data);
        $data  = substr(substr($data, 0,strlen ($data) - 4), 2);
        $c_len = strlen($data);
        
        $fd = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00\x00\x00\x00\x00"
              .pack("V", $crc)
              .pack("V", $c_len)
              .pack("V", $u_len)
              .pack("v", strlen($name))
              .pack("v", 0)
              .$name
              .$data
              .pack("V", $crc)
              .pack("V", $c_len)
              .pack("V", $u_len);
        
        $this->zdata[] = $fd;
                
        $cd = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00\x00\x00\x00\x00"
              .pack("V", $crc)
              .pack("V", $c_len)
              .pack("V", $u_len)
              .pack("v", strlen ($name))
              .pack("v", 0)
              .pack("v", 0)
              .pack("v", 0)
              .pack("v", 0)
              .pack("V", 32 )
              .pack("V", $this->offset)
              .$name;
  
        $this->offset = strlen(implode('', $this->zdata));
        
        $this->cdir[] = $cd;
    }
    // END


    // -------------------------------------------
    //  Output final zip file
    // -------------------------------------------  

    function output_zipfile()
    {

        $data = implode("", $this->zdata);
        $cdir = implode("", $this->cdir);


        return   $data
                .$cdir
                ."\x50\x4b\x05\x06\x00\x00\x00\x00"
                .pack("v", sizeof($this->cdir))
                .pack("v", sizeof($this->cdir))
                .pack("V", strlen($cdir))
                .pack("V", strlen($data))
                ."\x00\x00";
    }
    // END
}
// END CLASS
?>