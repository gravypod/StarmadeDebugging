<?php
	if (php_sapi_name() != "cli") {
		die("You do not belong here");
	}
	
	if (isStarmadeOnline()) {
		die("Server is online, exiting\n");
	}
	
	define("SCRIPT_CWD", realpath(".") . DIRECTORY_SEPARATOR);
	define("TEMP_DIR", SCRIPT_CWD . uniqid() . DIRECTORY_SEPARATOR);
	define("STARMADE_DIR", realpath("/home/starmade/")  . DIRECTORY_SEPARATOR);
	define("WEB_DIR", SCRIPT_CWD . "dumps" . DIRECTORY_SEPARATOR);
	define("LOG_FILE", SCRIPT_CWD . "dumplog." . getFilenameDate() . ".txt");
	
	ifMake(TEMP_DIR);
	ifMake(WEB_DIR);
	
	$zip = getTempZip();
	$logCopyFolder = getLogFolder(); // The folder we want to put the log files in
	$pid = getPID(); // The PID
	$stack = getStackFile(); // The stack file we want to make
	$logFiles = getLogFiles(); // The files we want to copy
	$zip = getTempZip(); // Get the destination of the resulting zip file
	
	execute_command("Making a stack dump", "jstack -F $pid >> $stack"); // Use JStack for a dump
	execute_command("Making a copy of the logs", "cp $logFiles $logCopyFolder"); // Copy in the logs

	$workingDir = getcwd();
	
	log_message("Enering TMP dir to " . TEMP_DIR);
	chdir(TEMP_DIR);
	
	execute_command("Making making final zip", "zip -r $zip ./"); // Zip up our resulting files
	
	log_message("Exiting TMP directory");
	chdir($workingDir);
	
	delete_folder(TEMP_DIR); // Delete all of the temp files
	
	function getLogFiles() { // Get the log files we want to backup
		return STARMADE_DIR . "logs/log.*";
	}
	function getLogFolder() { // Get the log folder we want to store log files in
		$dir = TEMP_DIR . "logs/";
		ifMake($dir);
		return $dir;
	}
	function getTempZip() { // Get the place to store the zip file in
		ifMake(WEB_DIR);
		return WEB_DIR .  getFilenameDate() . ".zip";
	}
	function getPID() { // Get the PID of the starmade process
		$out = array();
		exec("ps ax | grep 'StarMade.jar -server' | grep 'grep' -v | awk '{print $1}'", $out);
		return trim($out[0]);
	}
	function getFilenameDate() { // Get the filename friendly date
		return date("Y-m-d_h:i:s");
	}
	function getStackFile() { // Where to store the stack trace?
		return TEMP_DIR . "stack.log";
	}
	function ifMake($f) { // If a file does not exist, make it!
		if (!file_exists($f)) {
			mkdir($f, 0777, true);
		}
	}
	class StarMadeQueryException extends Exception {
		// Exception thrown by StarMadeQuery class
	}
	class StarMadeQuery {
		/*
		* Class written by rhaamo
		* Website: http://sigpipe.me
		* GitHub: https://github.com/rhaamo/
		*/
		private $Socket;
		private $Players;
		private $Info;
		public function Connect($Ip, $Port = 4242, $Timeout = 3) {
			if(!is_int($Timeout) || $Timeout < 0)	{
				throw new InvalidArgumentException('Timeout must be an integer.');
			}
			$this->Socket = @FSockOpen('tcp://' . $Ip, (int)$Port, $ErrNo, $ErrStr, $Timeout);
			if($ErrNo || $this->Socket === false)	{
				throw new StarMadeQueryException('Could not create socket: ' . $ErrStr);
			}
			Stream_Set_Timeout($this->Socket, $Timeout);
			Stream_Set_Blocking($this->Socket, true);
			try	{
				$this->GetInfos();
			} catch(StarMadeQueryException $e) { // We catch this because we want to close the socket, not very elegant
				FClose($this->Socket);
				throw new StarMadeQueryException($e->getMessage());
			}
			FClose($this->Socket);
		}
		public function GetInfo() {
			return isset($this->Info) ? $this->Info : false;
		}
		private function GetInfos() {
			$Data = $this->GetSocketStuff();
			if(!$Data){
				throw new StarMadeQueryException("Failed to receive status.");
			}
			if (count($Data) < 4) {
				throw new StarMadeQueryException("$Data doesn't contain three elements.");
			}
			$Info = Array();
			$Info['Players'] = IntVal($Data['nbplayers']);
			$Info['MaxPlayers'] = IntVal($Data['maxplayers']);
			$this->Info = $Info;
		}
		private function GetSocketStuff( ) {
			// Send magic thing
			//$Command = hex2bin(MAGIC); // PHP >= 5.4.0
			$Magic = "000000092affff016f00000000";
			$magic_pack = "h1firstpos/N1nbplayers/h1secondpos/N1maxplayers";
			$Command = pack("H*", $Magic);
			$Length = strlen($Command);
			if( $Length !== FWrite( $this->Socket, $Command, $Length ) ) {
				throw new MinecraftQueryException( "Failed to write on socket." );
			}
			$waste = FRead($this->Socket, 72);
			if( $waste === false ) {
				throw new StarMadeQueryException( "Failed to read from socket." );
			}
			// Get Real Datas
			$data = FRead($this->Socket, 82);
			if( $data === false ) {
				throw new StarMadeQueryException( "Failed to read from socket." );
			}
			return unpack($magic_pack, $data);
		}
	}
	function isStarmadeOnline() {
			$online = starmade_ping();
			if (!$online) { // Did the server respond to the ping?
					for ($i = 1; $i < 2; $i++) { // Lets try a few more times if it didn't.
							if (starmade_ping()) { // Yay, we are back online.
									return true; // Tell Checkers nothing is needed
							}
					}
			}
			return $online; // Message will be sent
	}
	function starmade_ping() {
		$q = new StarMadeQuery(); // Code to check uptime of SM.
		try {
			$q->Connect("sm.elwyneternity.com", 4242, 12); // Port and timeout. 
			return true;
		} catch (StarMadeQueryException $e) { // Starmade is offline?
			return false;
		}
	}
	function log_message($message) {
		$date = getFilenameDate();
		$m = "[$date] $message\n";
		echo $m;
		file_put_contents(LOG_FILE, $m);
	}
	function delete_folder($path) {
		if (is_dir($path)) {
			$files = array_diff(scandir($path), array('.', '..'));
			foreach ($files as $file) {
				delete_folder(realpath($path) . '/' . $file);
			}
			return rmdir($path);
		} else if (is_file($path)) {
			return unlink($path);
		}
		
		return false;
	}
	function execute_command($message, $command) {
		log_message("$message ('$command')");
		shell_exec($command);
	}
?>

