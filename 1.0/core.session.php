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
 File: core.session.php
-----------------------------------------------------
 Purpose: Session management class.
=====================================================

There are three validation types, set in the config file: 
 
  1. User cookies AND session ID (cs)
        
    This is the most secure way to run a site.  Three cookies are set:
    1. Session ID - This is a unique hash that is randomly generated when someone logs in.
    2. Password hash - The encrypted password of the current user
    3. Unique ID - The permanent unique ID hash associated with the account.
    
    All three cookies expire when you close your browser OR when you have been 
    inactive longer than two hours (one hour in the control panel).
    
    Using this setting does NOT allow 'stay logged-in' capability, as each session has a finite lifespan.

  2. Cookies only - no session ID (c)
    
    With this validation type, a session is not generated, therefore
    users can remain permanently logged in.
    
    This setting is obviously less secure because it does not provide a safety net
    if you share your computer or access your site from a public computer.  It relies
    solely on the password/unique_id cookies.

  3. Session ID only (s).  
    
    Most compatible as it does not rely on cookies at all.  Instead, a URL query string ID 
    is used.
    
    No stay-logged in capability.  The session will expire after one hour of inactivity, so
    in terms of security, it is preferable to number 2.
    
    
    NOTE: The control panel and public pages can each have their own session preference.
*/

if ( ! defined('EXT'))
{
    exit('Invalid file request');
}


class Session {
    
// following modified by Dave, 3/13/04, based on Sue's suggestion
// at http://www.pmachine.com/forum/threads.php?id=13799_0_19_0_C
// changed "var $cpan_session_len = 3600" from 3600 to 7200

    var $user_session_len = 7200;  // User sessions expire in two hours
    var $cpan_session_len = 7200;  // Admin sessions expire in one hour

    var $c_session        	= 'sessionid';
    var $c_uniqueid       	= 'uniqueid';
    var $c_password       	= 'userhash';
    var $c_anon             = 'anon';
    var $c_prefix         	= '';
        
    var $sdata            	= array();
    var $userdata         	= array();
    var $tracker            = array();
        
    var $validation_type  	= '';
    var $session_length   	= '';
    
    var $cookies_exist    	= FALSE;
    var $access_cp        	= FALSE;
    
    var $gc_probability   	= 10;  // Garbage collection probability.  Used to kill expired sessions.


    // --------------------------------------
    //  Session constructor
    // --------------------------------------    

    function Session()
    {
        global $IN, $DB, $OUT, $PREFS, $FNS, $LOC;
        
        // Is the user banned?
        // We only look for banned IPs if it's not a control panel request.
        // We test for banned admins separately in 'core.system.php'
        
        $ban_status = FALSE;
        
        if (REQ != 'CP')
        {
            if ($this->ban_check('ip'))
            {
                switch ($PREFS->ini('ban_action'))
                {
                    case 'message' : return $OUT->fatal_error($PREFS->ini('ban_message'));
                        break;
                    case 'bounce'  : $FNS->bounce($PREFS->ini('ban_destination')); exit;
                        break;
                    default        : $ban_status = TRUE;
                        break;        
                }
            }
        }
        
                 
        // Set session length.
        
        $this->session_length = (REQ == 'CP') ? $this->cpan_session_len : $this->user_session_len;
 
        // Set USER-DATA as GUEST until proven otherwise   
             
        $this->userdata = array(
                                'username'          => $IN->GBL('my_name', 'COOKIE'),
                                'screen_name'       => '',
                                'email'             => $IN->GBL('my_email', 'COOKIE'),
                                'url'               => $IN->GBL('my_url', 'COOKIE'),
                                'location'          => $IN->GBL('my_location', 'COOKIE'),
                                'language'          => '',
                                'timezone'          => '',
                                'daylight_savings'  => '',
                                'time_format'       => 'us',
                                'group_id'          => '3',
                                'access_cp'         =>  0,
                                'is_banned'         =>  $ban_status,
                               );
        

        // Set SESSION data as GUEST until proven otherwise
        
        $user_agent = ( ! isset($_SERVER['HTTP_USER_AGENT'])) ? '' : substr($_SERVER['HTTP_USER_AGENT'], 0, 50);
        
        $this->sdata = array(
                                'session_id' =>  0,
                                'member_id'  =>  0,
                                'admin_sess' =>  0,
                                'ip_address' =>  $IN->IP,
                                'user_agent' =>  $user_agent,
                                'last_visit' =>  $LOC->now
                            );
                            
          
        // Fetch the session ID from either the cookie or the $_GET query string.

        if ( ! $IN->GBL($this->c_session, 'COOKIE'))
        {
            if ( ! $IN->GBL('S', 'GET'))
            {
                // If session IDs are being used in public pages
                // the session will be found here
            
                if ($IN->SID != '')
                {
                    $this->sdata['session_id'] = $IN->SID;                
                }
            }
            else
            {
                $this->sdata['session_id'] = $IN->GBL('S', 'GET');
            }
        }
        else
        {
            $this->sdata['session_id'] = $IN->GBL($this->c_session, 'COOKIE');
        }
        
        
         // Determine if password/unique_id hash cookies exist.       
        
        if ($IN->GBL($this->c_uniqueid, 'COOKIE')  AND  $IN->GBL($this->c_password, 'COOKIE'))
        {
            $this->cookies_exist = TRUE;
        }
        
        // Verify that the config file has one of the three validation types
        // If not, default to "cs" - cookies and session for CP and cookies only for site

        if (REQ == 'CP')
        {
			if ($PREFS->ini('admin_session_type') != 'c' AND $PREFS->ini('admin_session_type') != 's' AND $PREFS->ini('admin_session_type') != 'cs')
			{
				$PREFS->core_ini['admin_session_type'] = 'cs';
			}
        }
        else
        {
			if ($PREFS->ini('user_session_type') != 'c' AND $PREFS->ini('user_session_type') != 's' AND $PREFS->ini('user_session_type') != 'cs')
			{
				$PREFS->core_ini['user_session_type'] = 'c';
			}
        }
        
         
        // Set our validation type based on whether it is a control panel request or site request 
         
        $this->validation = (REQ == 'CP') ? $PREFS->ini('admin_session_type') : $PREFS->ini('user_session_type');

      
        // Main session conditionals
      
        switch ($this->validation)
        {
            case 'cs' :
        
                    if ($this->sdata['session_id'] != '0'  AND  $this->cookies_exist == TRUE)
                    { 
                        if ($this->fetch_session_data()  AND  $this->fetch_member_data())
                        {
                            $this->update_member_session();
                        }
                        else
                        {
							$this->fetch_guest_data();
                        }
                    }
                    else
                    {
                    	$this->fetch_guest_data();
                    }
                    
                    $this->delete_old_sessions(); 
            
                break;
            case 'c'  :
            
                    if ($this->cookies_exist  AND  $this->fetch_member_data())
                    {
                        $this->update_member_session();
                    }
                    else
                    {
                    	$this->fetch_guest_data();
                    }
                    
                break;        
            case 's'  :
            
                    if ($this->sdata['session_id'] != '0')
                    {
                        if ($this->fetch_session_data()  AND  $this->fetch_member_data())
                        {
                            $this->update_member_session();
                        }
                        else
                        {
							$this->fetch_guest_data();
                        }
                    }
                    else
                    {
                    	$this->fetch_guest_data();
                    }
                    
                    $this->delete_old_sessions();
                    
               break;
        }
        
        // Merge userdata and session data into one array for portability
        
        $this->userdata = array_merge($this->userdata, $this->sdata);  
        
        
		// Fetch "tracker" cookie
        
        if (REQ != 'CP')
        {                     
            $this->tracker = $this->tracker();
		}
        
    }
    // END                
                
                
                
    // ----------------------------------------------
    //  Fetch session data
    // ----------------------------------------------    
  
    function fetch_session_data()
    {  
        global $DB, $LOC;

            // Look for session.  Match the user's IP address and browser for added security.
            
            $query = $DB->query("SELECT member_id, admin_sess, last_visit 
                                 FROM   exp_sessions 
                                 WHERE  session_id  = '".$this->sdata['session_id']."'
                                 AND    ip_address  = '".$this->sdata['ip_address']."' 
                                 AND    user_agent  = '".$this->sdata['user_agent']."'"
                                );

            if ($query->num_rows == 0)
            { 
                $this->reset_session_data();
            
                return false;               
            }
            
            if ($query->row['member_id'] == 0)
            {
                $this->reset_session_data();

                return false;
            }
            
            // Assign member ID to session array
            
            $this->sdata['member_id'] = $query->row['member_id'];
            
            
            // Is this an admin session?
                            
            if ($query->row['admin_sess'] == 1)
            {                    
                $this->sdata['admin_sess'] = 1; 
            }
            
            // If session has expired, delete it and set session data to GUEST
            
            if ($query->row['last_visit'] < ($LOC->now - $this->session_length))
            {                    
                $DB->query("DELETE FROM exp_sessions WHERE  session_id  = '".$this->sdata['session_id']."'"); 
                   
                $this->reset_session_data();
                
                return false;
            }

        return true;       
    }
    // END
  
   
   
    // ----------------------------------------------
    //  Fetch guest data
    // ----------------------------------------------    
  
    function fetch_guest_data()
    {  
        global $DB;

		$query = $DB->query("SELECT * FROM exp_member_groups WHERE group_id = '3'");
	
		foreach ($query->row as $key => $val)
		{            
			$this->userdata[$key] = $val;                 
		}
			$this->userdata['total_comments'] = 0;                 
			$this->userdata['total_entries']  = 0;                 
	}
	// END
   
                
                
    // ----------------------------------------------
    //  Fetch member data
    // ----------------------------------------------    
  
    function fetch_member_data()
    {  
        global $IN, $DB, $LOC;

        // Query DB for member data.  Depending on the validation type we'll
        // either use the cookie data or the member ID gathered with the session query.
        
        $sql = " SELECT exp_members.weblog_id, 
						exp_members.tmpl_group_id, 
						exp_members.username, 
						exp_members.screen_name, 
						exp_members.member_id, 
						exp_members.email, 
						exp_members.url, 
						exp_members.location,
						exp_members.last_visit, 
						exp_members.total_entries,
						exp_members.total_comments,
						exp_members.language, 
						exp_members.timezone, 
						exp_members.daylight_savings, 
						exp_members.time_format,
						exp_members.last_email_date,
						exp_members.notify_by_default,
						";
                       
        if (REQ == 'CP')
        {
            $sql .= "	exp_members.upload_id,
						exp_members.theme,
						exp_members.quick_links,
						exp_members.template_size,";
        }
                       
                       
         $sql .= "exp_member_groups.*
                  FROM exp_members, exp_member_groups ";

        if ($this->validation == 'c' || $this->validation == 'cs')
        {
            $sql .= "WHERE  unique_id = '".$IN->GBL($this->c_uniqueid, 'COOKIE')."'
                     AND    password  = '".$IN->GBL($this->c_password, 'COOKIE')."' 
                     AND    exp_members.group_id = exp_member_groups.group_id";
        }
        else
        {
            $sql .= "WHERE  member_id = '".$this->sdata['member_id']."'
                     AND    exp_members.group_id = exp_member_groups.group_id";
        }
        
        
        $query = $DB->query($sql);
        
        if ($query->num_rows == 0)
        {
            $this->reset_session_data();
        
            return false;
        }
        
        // Turn the query rows into array values
	
		foreach ($query->row as $key => $val)
		{            
			$this->userdata[$key] = $val;                 
		}
		
		
		
        // -----------------------------------------------------
        //  Assign Weblog, Template, and Module Access Privs
        // ----------------------------------------------------- 
                           
        if (REQ == 'CP')
        {
            // Fetch weblog privileges
            
            $assigned_blogs = array();
         
			if ($this->userdata['group_id'] == 1)
			{
            	$result = $DB->query("SELECT weblog_id FROM exp_weblogs WHERE is_user_blog = 'n'");
			}
			else
			{
            	$result = $DB->query("SELECT weblog_id FROM exp_weblog_member_groups WHERE group_id = '".$this->userdata['group_id']."'");
            }
            
            if ($result->num_rows > 0)
            {
                foreach ($result->result as $row)
                {
                    $assigned_blogs[$row['weblog_id']] = TRUE;
                }
            }
            
            $this->userdata['assigned_weblogs'] = $assigned_blogs;

            // Fetch module privileges
            
            $assigned_modules = array();
            
            $result = $DB->query("SELECT module_id FROM exp_module_member_groups WHERE group_id = '".$this->userdata['group_id']."'");
            
            if ($result->num_rows > 0)
            {
                foreach ($result->result as $row)
                {
                    $assigned_modules[$row['module_id']] = TRUE;
                }
            }
                
            $this->userdata['assigned_modules'] = $assigned_modules;
            
            
            // Fetch template group privileges
            
            $assigned_template_groups = array();
            
            $result = $DB->query("SELECT template_group_id FROM exp_template_member_groups WHERE group_id = '".$this->userdata['group_id']."'");
            
            if ($result->num_rows > 0)
            {
                foreach ($result->result as $row)
                {
                    $assigned_template_groups[$row['template_group_id']] = TRUE;
                }
            }
                
            $this->userdata['assigned_template_groups'] = $assigned_template_groups;
        }
		
		
        // Does the member have admin privileges?
        
        if ($query->row['can_access_cp'] == 'y')
        {
            $this->access_cp = TRUE;
        }
        else
        {
            $this->sdata['admin_sess'] = 0; 
        }
        
        // Update the session array with the member_id
        
        if ($this->validation == 'c')
        {
            $this->sdata['member_id'] = $query->row['member_id'];  
        }
               
        // Update member 'last visit' date field for this member
        
        if (($query->row['last_visit'] + 300) < $LOC->now)     
        {
        	$sql = "UPDATE exp_members set last_visit = '".$LOC->now."' WHERE member_id = '".$DB->escape_str($this->sdata['member_id'])."'";
        
            $DB->query($sql);
        }

        return true;  
    }
    // END
       
            
  
    // ----------------------------------------------
    //  Update Member session
    // ----------------------------------------------
    
    // New sessions can only be created by logging in.
    // This routine only updates an existing session.  

    function update_member_session()
    {  
        global $DB, $FNS;
        
        if ($this->validation == 's' || $this->validation == 'cs')
        {
            $sql = $DB->update_string('exp_sessions', $this->sdata, "session_id ='".$this->sdata['session_id']."'");
            
            $DB->query($sql); 
        }

        // Update session ID cookie
        
        if ($this->validation == 'cs')
        {
            $FNS->set_cookie($this->c_session , $this->sdata['session_id'],  $this->session_length);   
        }

            
        // If we only require cookies for validation, set admin session.   
            
        if ($this->validation == 'c'  AND  $this->access_cp == TRUE)
        {            
            $this->sdata['admin_sess'] = 1;
        }            
    }  
    // END  
  
  
    // ----------------------------------------------
    //  Reset session data as GUEST
    // ----------------------------------------------    
  
    function reset_session_data()
    {  
        $this->sdata['session_id'] = 0;   
        $this->sdata['admin_sess'] = 0;
        $this->sdata['member_id']  = 0;
    }
    // END     



    // --------------------------------
    //  Tracker
    // --------------------------------
    
    // This functions lets us store the visitor's last five pages viewed
    // in a cookie.  We use this to facilitate redirection after logging-in,
    // or other form submissions
        
    function tracker()
    {    
		global $IN, $FNS, $REGX;
		
		
		$tracker = $IN->GBL('tracker', 'COOKIE');

		if ($tracker != FALSE)
		{
			$tracker = unserialize($tracker);
		}
		
		if ( ! is_array($tracker))
		{
			$tracker = array();
		}
				
		$URI = ($IN->URI == '') ? 'index' : $IN->URI;
		
		$URI = str_replace("\\", "/", $URI); 
		
		// Kill naughty stuff
		// If someone is messing with the URL and adding stuff like <script>alert('boo')</script>
		// we don't want to pass it to the cookie for obvious reasons so we'll kill anything
		// that isn't kosher.
 
		$bad = array(
						"<!--"		=> "",
						"-->"		=> "",
						"%20"		=> "",
						"%22"		=> "",
						"%3c"		=> "",
						"%253c"		=> "",
						"%3e"		=> "",
						"%0e"		=> "",
						"%28"		=> "",
						"%29"		=> "",
						"%2528"		=> "",
						"%26"		=> "",
						"%24"		=> "",
						"%3f"		=> "",
						"%3b"		=> "",
						"%3d"		=> "",
						","			=> "",
						";"			=> "",
						"("			=> "",
						")"			=> "",
						"+"			=> "",
						"!"			=> "",
						"["			=> "",
						"]"			=> "",
						"@"			=> "",
						"^"			=> "",
						"'"			=> "",
						"\""		=> "",
						"~"			=> "",
						"*"			=> "",
						"|"			=> ""
					  );	
				
        foreach ($bad as $key => $val)
        {
			$URI = str_replace($key, $val, $URI);   
        }
				
		$URI = strip_tags($URI);
		
		if ( ! isset($tracker['0']))
		{
			$tracker[] = $URI;
		}
		else
		{
			if (count($tracker) == 5)
			{
				array_pop($tracker);
			}
			
			if ($tracker['0'] != $URI)
			{
				array_unshift($tracker, $URI);
			}
		}
	    
	    if (REQ == 'PAGE')
	    {	    
            $FNS->set_cookie('tracker', serialize($tracker), '0'); 
		}
		
		return $tracker;
    }
    // END
      


    // ----------------------------------------------
    //  Check for banned data
    // ----------------------------------------------    
  
    function ban_check($type = 'ip', $match = '')
    {  
        global $IN, $FNS, $PREFS, $OUT;
            
    	switch ($type)
    	{
    		case 'ip'			: $ban = $PREFS->ini('banned_ips');
    							  $match = $IN->IP;
    			break;
    		case 'email'		: $ban = $PREFS->ini('banned_emails');
    			break;
    		case 'username'		: $ban = $PREFS->ini('banned_usernames');
    			break;
    		case 'screen_name'	: $ban = $PREFS->ini('banned_screen_names');
    			break;
    	}
        
        if ($ban == '')
        {
            return FALSE;
        }
        
        foreach (explode('|', $ban) as $val)
        {
        	if (ereg("\*$", $val))
        	{
        		$val = str_replace("*", "", $val);

				if (ereg("^$val", $match))
				{
					return TRUE;
				}
        	}
        	elseif (ereg("^\*", $val))
        	{ 
        		$val = str_replace("*", "", $val);
        	
				if (ereg("$val$", $match))
				{
					return TRUE;
				}
        	}
        	else
        	{
				if (ereg("^$val$", $match))
				{
					return TRUE;
				}
			}
        }

        return false;
    }
    // END     
  
     
    // ----------------------------------------------
    //  Delete old sessions if probability is met
    // ----------------------------------------------
    
    // By default, the probablility is set to 10 percent.
    // That means sessions will only be deleted one
    // out of ten times a page is loaded.
    
    function delete_old_sessions()
    {    
        global $DB, $PREFS, $LOC;
                
        $expire = $LOC->now - $this->session_length;
  
        srand(time());
  
        if ((rand() % 100) < $this->gc_probability) 
        {                 
            $sql = "DELETE FROM exp_sessions WHERE last_visit < $expire ";
            
            if (REQ == 'CP')
            {
                $sql .= "AND admin_sess = '1'";
            }
            else
            {
                $sql .= "AND admin_sess = '0'";
            }    

            $DB->query($sql);             
        }    
    }
    // END
    
    
    
    // ----------------------------------------------
    //  Save password lockout
    // ----------------------------------------------
        
    function save_password_lockout()
    {    
        global $IN, $DB, $PREFS;
        
		if ($PREFS->ini('password_lockout') == 'n')
		{
         	return; 
        } 

		$sql = "INSERT INTO exp_password_lockout (login_date, ip_address, user_agent) VALUES ('".time()."', '".$DB->escape_str($IN->IP)."', '".$this->userdata['user_agent']."')";  
    
		$query = $DB->query($sql);
    }
    // END
    
    
    
    // ----------------------------------------------
    //  Check password lockout
    // ----------------------------------------------
        
    function check_password_lockout()
    {    
        global $IN, $DB, $PREFS;
        
		if ($PREFS->ini('password_lockout') == 'n')
		{
         	return FALSE; 
        } 
        
        if ($PREFS->ini('password_lockout_interval') == '')
        {
         	return FALSE; 
        }
        
        $interval = $PREFS->ini('password_lockout_interval') * 60;
        
        $expire = time() - $interval;
  
  		$sql = "SELECT count(*) AS count 
  				FROM exp_password_lockout 
  				WHERE login_date > $expire 
  				AND ip_address = '".$IN->IP."'
  				AND user_agent = '".$this->userdata['user_agent']."'";
  
		$query = $DB->query($sql);
		
		if ($query->row['count'] >= 4)
		{
			return TRUE;
		}
		else
		{
			return FALSE;
		}
    }
    // END
    
    
    // ----------------------------------------------
    //  Delete old password lockout data
    // ----------------------------------------------
        
    function delete_password_lockout()
    {    
        global $DB, $PREFS;
        
		if ($PREFS->ini('password_lockout') == 'n')
		{
         	return FALSE; 
        } 
                
        $interval = $PREFS->ini('password_lockout_interval') * 60;
        
        $expire = time() - $interval;
  
        srand(time());
  
        if ((rand() % 100) < $this->gc_probability) 
        {                 
            $DB->query("DELETE FROM exp_password_lockout WHERE login_date < $expire");             
        }    
    }
    // END
}
// END CLASS
?>