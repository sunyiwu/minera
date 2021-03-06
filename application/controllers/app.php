<?php if (!defined('BASEPATH')) die();
class App extends Main_Controller {

	/*
	// Index/lock screen controller
	*/
	public function index()
	{	
		$data['htmlTag'] = "lockscreen";
		$data['pageTitle'] = "Welcome to Minera";
		$this->load->view('include/header', $data);
		$this->load->view('lockscreen');
	}
	
	/*
	// Login controller
	*/
	public function login()
	{
		if ($this->input->post('password', true) && $this->input->post('password', true) == $this->redis->get('minera_password'))
		{
			$this->session->set_userdata("loggedin", 1);
			redirect('app/dashboard');
		}
		else
			redirect('app/index');
	}
	
	/*
	// Dashboard controller
	*/
	public function dashboard()
	{
		if (!$this->session->userdata("loggedin"))
			redirect('app/index');
		
		$data['minerdPools'] = json_decode($this->util_model->getPools());
		$data['btc'] = $this->util_model->getBtcUsdRates();
		$data['ltc'] = $this->util_model->getCryptsyRates(3);
		$data['doge'] = $this->util_model->getCryptsyRates(132);
		$data['isOnline'] = $this->util_model->isOnline();
		$data['minerdLog'] = $this->redis->get('minerd_log');
		$data['savedFrequencies'] = $this->redis->get('current_frequencies');
		$data['htmlTag'] = "dashboard";
		$data['appScript'] = true;
		$data['settingsScript'] = false;
		$data['mineraUpdate'] = $this->util_model->checkUpdate();
		$data['dashboard_refresh_time'] = $this->redis->get("dashboard_refresh_time");
		$data['pageTitle'] = "Minera - Dashboard";
		
		$this->load->view('include/header', $data);
		$this->load->view('include/sidebar', $data);
		$this->load->view('frontpage', $data);
		$this->load->view('include/footer');
	}

	/*
	// Settings controller
	*/
	public function settings()
	{
		if (!$this->session->userdata("loggedin"))
			redirect('app/index');
			
		$extramessages = false;
			
		if ($this->input->post('save_settings'))
		{
			$dashSettings = trim($this->input->post('dashboard_refresh_time'));
			$poolUrls = $this->input->post('pool_url');
			$poolUsernames = $this->input->post('pool_username');
			$poolPasswords = $this->input->post('pool_password');

			$pools = array();
			foreach ($poolUrls as $key => $poolUrl)
			{
				if ($poolUrl)
				{
					if (isset($poolUsernames[$key]) && isset($poolPasswords[$key]))
					{
						if ($this->util_model->checkPool($poolUrl))
						{
							$pools[] = array("url" => $poolUrl, "username" => $poolUsernames[$key], "password" => $poolPasswords[$key]);	
						}
						else
						{
							$extramessages[] = "I cannot add this pool <strong>$poolUrl</strong> because it doesn't seem to be alive";
						}
					}
				}
			}

			// Start creating command options string
			$settings = null;
			$confArray = array();

			// Save manual/guided selection
			$this->redis->set('manual_options', $this->input->post('manual_options'));
			$this->redis->set('guided_options', $this->input->post('guided_options'));

			if ($this->input->post('manual_options'))
			{
				// Manual options
				$settings = trim($this->input->post('minerd_manual_settings'));
				$this->redis->set('minerd_manual_settings', $settings);

			}
			else
			{
				// Guided options
				
				// Auto-detect
				if ($this->input->post('minerd_autodetect'))
				{
					$confArray["gc3355-detect"] = true;			
				}
				$this->redis->set('minerd_autodetect', $this->input->post('minerd_autodetect'));

				// Autotune
				if ($this->input->post('minerd_autotune'))
				{
					$confArray["gc3355-autotune"] = true;
				}
				$this->redis->set('minerd_autotune', $this->input->post('minerd_autotune'));
					
				// Start frequency
				if ($this->input->post('minerd_startfreq'))
				{
					$confArray["freq"] = $this->input->post('minerd_startfreq');
				}
				$this->redis->set('minerd_startfreq', $this->input->post('minerd_startfreq'));
				
				// Logging
				if ($this->input->post('minerd_log'))
				{
					$confArray["log"] = $this->config->item("minerd_log_file");
				}
				$this->redis->set('minerd_log', $this->input->post('minerd_log'));

				// Debug
				if ($this->input->post('minerd_debug'))
				{
					$confArray["debug"] = true;
				}
				$this->redis->set('minerd_debug', $this->input->post('minerd_debug'));

				// Extra options
				if ($this->input->post('minerd_extraoptions'))
				{
					$settings .= " ".$this->input->post('minerd_extraoptions')." ";
				}
				$this->redis->set('minerd_extraoptions', $this->input->post('minerd_extraoptions'));				
				
			}
			
			// Add the pools to the command
			$poolsArray = array();
			foreach ($pools as $pool)
			{
				$addPools[] = " -o ".$pool['url']." -u ".$pool['username']." -p ".$pool['password'];
				$poolsArray[] = array("url" => $pool['url'], "user" => $pool['username'], "pass" => $pool['password']);
			}
			$confArray['pools'] = $poolsArray;
			
			// Prepare JSON conf
			$jsonConfRedis = json_encode($confArray);
			$jsonConfFile = json_encode($confArray, JSON_PRETTY_PRINT);
			
			// Add JSON conf to miner command
			$settings .= " -c ".$this->config->item("minerd_conf_file");
			// Save the JSON conf file
			file_put_contents($this->config->item("minerd_conf_file"), $jsonConfFile);

			// End command options string

			$this->util_model->setPools($pools);
			$this->util_model->setCommandline($settings);
			$this->redis->set("minerd_json_settings", $jsonConfRedis);
			$this->redis->set("minerd_autorecover", $this->input->post('minerd_autorecover'));
			$this->redis->set("dashboard_refresh_time", $dashSettings);
			
			// System settings
			
			// Delay time
			$delay = 5;
			if ($this->input->post('minerd_delaytime'))
			{
				$delay = $this->input->post('minerd_delaytime');
				$this->redis->set("minerd_delaytime", $delay);
			}

			// On boot extra commands
			$extracommands = false;
			if ($this->input->post('system_extracommands'))
			{
				$extracommands = $this->input->post('system_extracommands');
			}
			$this->redis->set("system_extracommands", $extracommands);
						
			// Startup script rc.local
			$this->util_model->saveStartupScript($delay, $extracommands);

			$data['message'] = '<b>Success!</b> Settings saved!';
			$data['message_type'] = "success";
						
			if ($this->input->post('save_restart'))
			{
				$this->util_model->minerRestart();
				
				$data['message'] = '<b>Success!</b> Settings saved and miner restarted!';
				$data['message_type'] = "success";
			}

		}

		if ($this->input->post('save_password'))
		{
			$password = trim($this->input->post('password'));
			$password2 = trim($this->input->post('password2'));
			if (empty($password) && empty($password2))
			{
				$data['message'] = "<b>Warning!</b> Password can't be empty";
				$data['message_type'] = "warning";
			}
			elseif ($password != $password2)
			{
				$data['message'] = "<b>Warning!</b> Password mismatch";
				$data['message_type'] = "warning";				
			}
			else
			{
				$this->redis->set("minera_password", $password);
				$data['message'] = '<b>Success!</b> Password saved!';
				$data['message_type'] = "success";
			}
		}
		
		if (is_array($extramessages))
		{
			$data['message'] = '<b>Warning!</b> '.implode(" ", $extramessages);
			$data['message_type'] = "warning";
		}
		
		// Load Coin Rates
		$data['btc'] = $this->util_model->getBtcUsdRates();
		$data['ltc'] = $this->util_model->getCryptsyRates(3);
		$data['doge'] = $this->util_model->getCryptsyRates(132);
		
		// Load miner settings
		$data['minerdAutorecover'] = $this->redis->get('minerd_autorecover');
		$data['minerdAutodetect'] = $this->redis->get('minerd_autodetect');
		$data['minerdAutotune'] = $this->redis->get('minerd_autotune');
		$data['minerdStartfreq'] = $this->redis->get('minerd_startfreq');
		$data['minerdExtraoptions'] = $this->redis->get('minerd_extraoptions');
		$data['minerdLog'] = $this->redis->get('minerd_log');
		$data['minerdDebug'] = $this->redis->get('minerd_debug');
		$data['minerdManualSettings'] = $this->redis->get('minerd_manual_settings');
		$data['minerdSettings'] = $this->util_model->getCommandline();
		$data['minerdJsonSettings'] = $this->redis->get("minerd_json_settings");
		$data['minerdPools'] = $this->util_model->getPools();
		$data['minerdGuidedOptions'] = $this->redis->get("guided_options");
		$data['minerdManualOptions'] = $this->redis->get("manual_options");
		$data['minerdDelaytime'] = $this->redis->get("minerd_delaytime");
		
		//Load Dashboard settings
		$data['dashboard_refresh_time'] = $this->redis->get("dashboard_refresh_time");

		// Load System settings
		$data['systemExtracommands'] = $this->redis->get("system_extracommands");
				
		// Everything else
		$data['savedFrequencies'] = $this->redis->get('current_frequencies');
		$data['isOnline'] = $this->util_model->isOnline();
		$data['mineraUpdate'] = $this->util_model->checkUpdate();
		$data['htmlTag'] = "settings";
		$data['appScript'] = false;
		$data['settingsScript'] = true;
		$data['pageTitle'] = "Minera - Settings";
		
		$this->load->view('include/header', $data);
		$this->load->view('include/sidebar', $data);
		$this->load->view('settings', $data);
		$this->load->view('include/footer');
	}
	
	/*
	// Shutdown controller (this should be in a different "system" controller file)
	*/
	public function shutdown()
	{	
		if ($this->input->get('confirm'))
		{
			$data['message'] = "Please wait to unplug me.";
			$data['timer'] = true;
			$this->util_model->shutdown();
		}
		else
		{
			$data['title'] = "Are you sure?";
			$data['message'] = '<a href="'.site_url("app/shutdown").'?confirm=1"><button class="btn btn-default btn-lg"><i class="fa fa-check"></i> Yes, shutdown now</button></a>&nbsp;&nbsp;&nbsp;<a href="'.site_url("app/dashboard").'"><button class="btn btn-default btn-lg"><i class="fa fa-times"></i> No, thanks</button></a>';
			$data['timer'] = false;
		}
		
		$data['pageTitle'] = "Shutdown Minera";
		$data['messageEnd'] = "you can unplug me now.";
		$data['htmlTag'] = "lockscreen";
		$data['seconds'] = 30;
		$data['refreshUrl'] = false;
		$this->load->view('include/header', $data);
		$this->load->view('sysop', $data);
	}

	/*
	// Reboot controller (this should be in a different "system" controller file)
	*/
	public function reboot()
	{	
		if ($this->input->get('confirm'))
		{
			$data['message'] = "Please wait while I'm rebooting...";
			$data['timer'] = true;
			$this->util_model->reboot();
		}
		else
		{
			$data['title'] = "Are you sure?";
			$data['message'] = '<a href="'.site_url("app/reboot").'?confirm=1"><button class="btn btn-default btn-lg"><i class="fa fa-check"></i> Yes, reboot now</button></a>&nbsp;&nbsp;&nbsp;<a href="'.site_url("app/dashboard").'"><button class="btn btn-default btn-lg"><i class="fa fa-times"></i> No, thanks</button></a>';
			$data['timer'] = false;
		}
		
		$data['pageTitle'] = "Reboot Minera";
		$data['messageEnd'] = "here we go!";
		$data['htmlTag'] = "lockscreen";
		$data['seconds'] = 50;
		$data['refreshUrl'] = site_url("app/index");
		$this->load->view('include/header', $data);
		$this->load->view('sysop', $data);
	}

	/*
	// Start miner controller (this should be in a different "system" controller file)
	*/
	public function start_miner()
	{
		if (!$this->session->userdata("loggedin"))
			redirect('app/index');
		
		if (!$this->util_model->isOnline())
			$this->util_model->minerStart();
		else
		{
			$this->session->set_flashdata('message', "<b>Warning!</b> Your miner is currently mining, before you can start it you need to stop it before, or try the restart link.");
		}
			
		
		redirect('app/dashboard');
	}

	/*
	// Stop miner controller (this should be in a different "system" controller file)
	*/
	public function stop_miner()
	{
		if (!$this->session->userdata("loggedin"))
			redirect('app/index');
		
		$this->util_model->minerStop();
		
		redirect('app/dashboard');
	}
	
	/*
	// Restart miner controller (this should be in a different "system" controller file)
	*/
	public function restart_miner()
	{
		if (!$this->session->userdata("loggedin"))
			redirect('app/index');
		
		$this->util_model->minerRestart();
		
		redirect('app/dashboard');
	}
	
	/*
	// Update controller (this should be in a different "system" controller file)
	*/
	public function update()
	{
		if ($this->util_model->checkUpdate())
		{
			if ($this->input->get('confirm'))
			{
				$data['message'] = "Please wait while I'm upgrading the system...";
				$data['timer'] = true;
				$data['onloadFunction'] = "callUpdate()";
				$data['refreshUrl'] = site_url("app/index");
			}
			else
			{
				$data['title'] = "System update detected";
				$data['message'] = '<a href="'.site_url("app/update").'?confirm=1"><button class="btn btn-default btn-lg"><i class="fa fa-check"></i> Let me install the updates</button></a>&nbsp;&nbsp;&nbsp;<a href="'.site_url("app/dashboard").'"><button class="btn btn-default btn-lg"><i class="fa fa-times"></i> No, thanks</button></a>';
				$data['timer'] = false;
				$data['onloadFunction'] = false;
				$data['refreshUrl'] = false;
			}
			
			$data['pageTitle'] = "Updating Minera";
			$data['messageEnd'] = "System updated!";
			$data['htmlTag'] = "lockscreen";
			$data['seconds'] = 20;
			$this->load->view('include/header', $data);
			$this->load->view('sysop', $data);
		}
		else
		{
			redirect("app/dashboard");
		}
	}

	/*
	// API controller
	*/
	public function api()
	{
		switch($this->input->get('command'))
		{
			case "save_current_freq":
				$o = $this->util_model->saveCurrentFreq();
			break;
			case "select_pool":
				$o = json_encode($this->util_model->selectPool($this->input->get('poolId')));
				// Give to the stats the time to refresh
				sleep(3);
			break;
			case "update_minera":
				$o = $this->util_model->update();
			break;
		}
		
		$this->output
			->set_content_type('application/json')
			->set_output($o);
	}
	
	/*
	// Stats controller get the live stats
	*/
	public function stats()
	{
		$stats = $this->util_model->getStats();
		
		$this->output
			->set_content_type('application/json')
			->set_output($stats);
	}
	
	/*
	// Store controller Get the store stats from Redis
	*/
	public function stored_stats()
	{
		$storedStats = $this->util_model->getStoredStats(3600);
		
		$this->output
			->set_content_type('application/json')
			->set_output("[".implode(",", $storedStats)."]");
	}	

	/*
	// Cron controller to be used to run scheduled tasks
	*/
	public function cron()
	{
		// Check and restart the minerd if it's dead
		if ($this->redis->get('minerd_autorecover'))
		{
			$this->util_model->checkMinerIsUp();	
		}
		
		// Store the live stats to be used on time graphs
		$this->util_model->storeStats();
	}
	
	/*
	// Controllers for retro compatibility
	*/
	public function cron_stats()
	{
		redirect('app/cron');
	}

}

/* End of file frontpage.php */
/* Location: ./application/controllers/frontpage.php */
