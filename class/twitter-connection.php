<?php 

/*
A NOTE ON TWITTER ERRORS:
	ERROR 215 TYPICALLY MEANS YOU GOT THE AUTHENTICATION STRING FORAMT WRONG
	ERROR 32 MEANS YOU GOT THE VALUES WRONG
*/

class TwitterConnection{
	private $oauth_data = array();
	private $user_info = array();
	private $post_properties = array();
	
	function getPostProperties(){
		return $this->post_properties;
	}
	
	private $path_prefix ="";
	
	private $media_api = "https://upload.twitter.com/1.1/media/upload.json";
	private $status_api = "https://api.twitter.com/1.1/statuses/update.json";
	private $user_timeline_api = "https://api.twitter.com/1.1/statuses/user_timeline.json";	
	private $mention_timeline_api = "https://api.twitter.com/1.1/statuses/mentions_timeline.json";
	private $oembed_api = "https://publish.twitter.com/oembed";
	private $default_status_string = "https://twitter.com/:USERNAME/status/";
	
	function __construct($path_prefix = ""){
		$this->path_prefix  = $path_prefix;
		require_once("additional-functions.php");
		require_once("board-functions.php");
		require_once("database-connection.php");
		
		$this->oauth_data = 	 StandardFunctions::getIniFile($path_prefix . "settings/keys.ini");
		$this->user_info = 		 StandardFunctions::getIniFile($path_prefix . "settings/userinfo.ini");
		$this->post_properties = StandardFunctions::getIniFile($path_prefix . "settings/postproperties.ini");
		$this->buildStatusString();
	}
	
	function endConnection(){
		$this->database_connection = null;
	}
	
	//base64 processing
	function removeExtraSymbols($string){
		$string = str_replace(".", "a", $string);
		$string = str_replace("%", "b", $string);
		$string = str_replace("/", "c", $string);
		return $string;
	}
	
	function buildStatusString(){
		$this->default_status_string = str_replace(":USERNAME", $this->user_info["User-Name"], $this->default_status_string);
	}
	
	function getEmbededTweet($post_id, $width=550){
		$query_string = "url=" . rawurlencode($this->default_status_string . "$post_id") . "&maxwidth=" . $width;
		$curl = curl_init($this->oembed_api . "?$query_string");
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$content = curl_exec($curl);

		return json_decode($content,true);
	}
	
	function retrieveTimeline(){
		$highest_post_id = -1;
		echo $this->post_properties["TopPostNo"];
		$timeline_arr = $this->getUserTimeline($this->post_properties["TopPostNo"]);
		$timeline_database_arr = array();
		echo "<pre>";

		if($timeline_arr["errors"][0]["code"] ==  null && sizeof($timeline_arr) != 0){
			foreach ($timeline_arr as $timeline_item){
				$post_id = $timeline_item["id"];
				$post_text = $timeline_item["text"];
				$post_image_string = $this->grabTwitterImage($timeline_item);
				array_push($timeline_database_arr, [$post_id, $post_text, $post_image_string]);
				
				$highest_post_id = $post_id > $highest_post_id ? $post_id : $highest_post_id;
			}			
		}
		else echo $timeline_arr["errors"][0]["code"] . "Tim<br/>";
				//var_dump($timeline_arr);
		
		echo "<hr>"; //From the post ID try and find any replies
					
		$reply_arr = $this->getTweetReplies($this->post_properties["TopPostNo"]);	
		$reply_arr_container = array();
			
		if($reply_arr["errors"][0]["code"] ==  null && sizeof($reply_arr) != 0){
			$reply_id = 0;
			foreach($reply_arr as $reply_item){
				$reply_id = $reply_item["id"];
				$reply_text = $reply_item["text"];
				$reply_image_string =  $this->grabTwitterImage($reply_item);
				$responding_to_id = $reply_item['in_reply_to_status_id'];
				//echo "$reply_id -- $responding_to_id<hr/>";
				array_push($reply_arr_container, [$reply_id, $reply_text, $reply_image_string, $responding_to_id]);
				
				$highest_post_id = $reply_id > $highest_post_id ? $reply_id : $highest_post_id;
			}
		}
				else echo $reply_arr["errors"][0]["code"] . "Rep<br/>";
		
		if(sizeof($timeline_database_arr) + sizeof($reply_arr_container) == 0){
			echo "No Updates<hr/>";
			return;
		} 
		else{
			$postfile = fopen($this->path_prefix . "settings/postproperties.ini", "w");
			echo "TopPostNo=$highest_post_id<br/>";
			var_dump($postfile);
			fwrite($postfile, "TopPostNo=$highest_post_id\n" . "Catalog-Size=" . $this->post_properties["Catalog-Size"]);
		}
		
		$combined_database_arr = array_merge ($timeline_database_arr, $reply_arr_container);	
		return $combined_database_arr;
	}
		
	function grabTwitterImage($tweet_array){
		$first_join = false;
		$image_url_string = null;
		echo "<hr/>";
		if($tweet_array["extended_entities"] != null){
			foreach($tweet_array["extended_entities"] ["media"] as $entity){
				$filename_url ="";
				if(isset($entity["video_info"])){
					$filename_url = $entity["video_info"]["variants"][0]["url"];
					$extention = pathinfo($filename_url, PATHINFO_EXTENSION );
				}
				else{
					$filename_url = $entity["media_url_https"];
					$extention = pathinfo($filename_url , PATHINFO_EXTENSION );
				}
				
				$filename = "../images/" . (microtime(true) * 10000) . (rand(0,10000)) . ".$extention";
				echo "$filename_url: " .$filename;
		
				BoardFunctions::uploadMedia($filename, $filename_url);
				if(!$first_join){
					$first_join = true;
					$image_url_string = rawurlencode($filename);
				}
				else $image_url_string .= "," . rawurlencode($filename);
			}
				
		}	
	
		return $image_url_string;
	}
	
	function getUserTimeline($since_id = 976628662446551043, $count = 100){

		$random_value = OauthRandom::randomAlphaNumet(32);
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
		$timestamp = time();
		$reply_exclude = "false";

		echo $this->user_info["User-ID"];

		$get_fields  = "since_id=" . $since_id . "&count=" . $count . "&include_rts=false&exclude_replies=$reply_exclude&user_id=" . $this->user_info["User-ID"];
		//$msg_len = (strlen($this->user_timeline_api . "?$get_fields"));  //GET REQUESTS HAVE NO DYNAMIC LENGTH
		
		$param_array = 	array( "user_id" => $this->user_info["User-ID"],
								"since_id" => "$since_id",
								"exclude_replies"=>"$reply_exclude",
								"count" => "$count",
								"include_rts" => "false",
								"oauth_version" => "$oauth_version",
								"oauth_nonce"=>"$random_value",
								"oauth_token"=> $this->oauth_data["oauth_token"],
								"oauth_timestamp" => "$timestamp",
								"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
								"oauth_signature_method" => "$method"
								);
		
		$signature = rawurlencode($this->generateSignature(array(
											"base_url" => $this->user_timeline_api,
											"request_method" => "GET"), 
											$param_array,
										array(
											"consumer_secret" => $this->oauth_data["consumer_secret"],
											"oauth_secret" => $this->oauth_data["oauth_secret"]
											)
										));

		$param_array["oauth_signature"] = $signature;		
		$header_data = array("Accept: */*", "Connection: close","User-Agent: VerniyXYZ-CURL" ,
					"Content-Type: application/x-www-form-urlencoded;charset=UTF-8", "Host: api.twitter.com",
					$this->buildAuthorizationString($param_array));	
					
		//request
		echo "<hr/>";
		$curl = curl_init($this->user_timeline_api . "?$get_fields");
		curl_setopt($curl, CURLOPT_HTTPGET, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl,  CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER , false);
		
		echo "<br/>-- Fin -- <hr/>";
		$content = curl_exec($curl);
		return json_decode(($content), true); 
						
	}
	
		
	function getTweetReplies($current_post_id, $max_post_id = -1){
		$random_value = OauthRandom::randomAlphaNumet(32);
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
		$timestamp = time();
					

		$get_fields  = "since_id=" . $current_post_id;
		$param_array = 	array(
								"since_id" => "$current_post_id",
								"oauth_version" => "$oauth_version",
								"oauth_nonce"=>"$random_value",
								"oauth_token"=> $this->oauth_data["oauth_token"],
								"oauth_timestamp" => "$timestamp",
								"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
								"oauth_signature_method" => "$method"
								);
		if($max_post_id > 0){
			$get_fields .= $max_post_id > 0 ? "&max_id=" . $max_post_id : "";
			$param_array["max_id"] = "$max_post_id";
		}

		$signature = rawurlencode($this->generateSignature(array(
											"base_url" => $this->mention_timeline_api,
											"request_method" => "GET"), 
											$param_array,
										array(
											"consumer_secret" => $this->oauth_data["consumer_secret"],
											"oauth_secret" => $this->oauth_data["oauth_secret"]
											)
										));
		$param_array["oauth_signature"] = $signature;		
		$header_data = array("Accept: */*", "Connection: close","User-Agent: VerniyXYZ-CURL" ,
					"Content-Type: application/x-www-form-urlencoded;charset=UTF-8", "Host: api.twitter.com",
					$this->buildAuthorizationString($param_array));	
					
		//request
		$curl = curl_init($this->mention_timeline_api . "?$get_fields");
		curl_setopt($curl, CURLOPT_HTTPGET, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl,  CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER , false);
		$content = curl_exec($curl);
		return json_decode(($content), true); 		
	}
	
		
	
	
	function buildAuthorizationString($parameters){
		$authorization_string = 'Authorization: OAuth ';
		
		ksort($parameters);
		$is_first_join = false;
		foreach($parameters as $key => $value){
			if(!$is_first_join){
				$is_first_join = true;
				$authorization_string .= $key  . '="' . $value . '"';
			} 
			else{
				$authorization_string .= "," . $key . '="' . $value . '"';
			}
		}
		return $authorization_string;
	}
	
	function makeTweet($comment, $file_arr){
		$image_string = $this->addTweetMedia($file_arr);
		
		//access info
		$request_method = "POST";

		//message info
		$encode_tweet_msg = rawurlencode($comment);
		$include_entities = "true";

		//append to postfield_string the media code via media_ids=$media_id
		$postfield_string = "include_entities=$include_entities&status=$encode_tweet_msg&media_ids=$image_string";
		$msg_len = (strlen($postfield_string));

		$random_value = OauthRandom::randomAlphaNumet(32);
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
		$timestamp = time();

		$param_array = array("include_entities" => "$include_entities",
											"status" => "$encode_tweet_msg",
											"media_ids" => "$image_string",
											"oauth_version" => "$oauth_version",
											"oauth_nonce"=>"$random_value",
											"oauth_token"=> $this->oauth_data["oauth_token"],
											"oauth_timestamp" => "$timestamp",
											"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
											"oauth_signature_method" => "$method"
											);
		
						//add media id to the signature
		$signature = rawurlencode($this->generateSignature(array(
											"base_url" => $this->status_api,
											"request_method" => $request_method),
											$param_array,
										array(
											"consumer_secret" => $this->oauth_data["consumer_secret"],
											"oauth_secret" => $this->oauth_data["oauth_secret"]
											)
		));

		$param_array["oauth_signature"] = $signature;		
		$header_data = array("Accept: */*", "Connection: close","User-Agent: VerniyXYZ-CURL" ,
					"Content-Type: application/x-www-form-urlencoded;charset=UTF-8", 
					"Content-Length: $msg_len", "Host: api.twitter.com",
					$this->buildAuthorizationString($param_array));	
												
		//request
		echo "<hr/>";
		$curl = curl_init($this->status_api);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postfield_string);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		echo "<br/>-- Fin -- <hr/>";
		$content = curl_exec($curl);
		//var_dump (json_decode($content, true));
		return json_decode($content, true);
	}
	
	function addTweetMedia($file_arr){
		
		//image info
		$image_string = "";//delimited by ',' commas
		for($file = 0 ; $file < count($file_arr) ; $file++){
			if($file_arr[$file] != ""){
				//create data in binary/b64
				$mime_type = pathinfo($file_arr[$file], PATHINFO_EXTENSION);
				$file_arr[$file] = urldecode($file_arr[$file]);
				$binary = file_get_contents($this->path_prefix . $file_arr[$file]);

				$base64 = base64_encode($binary);
				
				//upload file to twitter and get id for use in files
				$size = filesize( $this->path_prefix . $file_arr[$file]);
				if($file == 0)
					$image_string = $this->getMediaID($base64, $size, 'image/' . $mime_type);
				else
					$image_string .= "," . $this->getMediaID($base64, $size, 'image/' . $mime_type);		
			}
		}
		return rawurlencode($image_string);
	}
	
	function getMediaID($base64, $size, $mime_type){		
		$random_value = OAuthRandom::randomAlphaNumet(32);
		$timestamp = time();

		echo "<br/><br/>";
		/////////////MAKE INIT////////////
		//post data
		$media_id = $this->mediaInit($size, $mime_type, $random_value, $timestamp);

		echo "<br/><br/>";

		/////////////MAKE APPEND////////////
		//post data
		$this->mediaAppend($base64, $media_id, $random_value, $timestamp);
		
		echo  "<br/><br/>";

		/////////////MAKE FINAL/
		$this->makeFinal($media_id, $random_value, $timestamp);	
		echo  "<br/><br/>";
		
		return $media_id ;
	}
	
	function mediaInit($size, $mime, $random_value, $timestamp){
		$command = "INIT";
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
				
		$postfield_string = "command=$command&total_bytes=$size&media_type=$mime";
		
		$msg_len = (strlen($postfield_string));
		//header data
			  // BUILD SIGNATURE				 
			$signature =   rawurlencode($this->generateSignature(array(
										"base_url" => $this->media_api,
										"request_method" => "POST"),
										array(
										"command" => "$command",
										"total_bytes" => "$size",
										"media_type" => "$mime",
										"oauth_version" => "$oauth_version",
										"oauth_nonce"=>"$random_value",
										"oauth_token"=> $this->oauth_data["oauth_token"],
										"oauth_timestamp" => "$timestamp",
										"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
										"oauth_signature_method" => "$method",
										),
										array(
										"consumer_secret" => $this->oauth_data["consumer_secret"],
										"oauth_secret" => $this->oauth_data["oauth_secret"]
										)
									));
										

		
		$header_data = array("Accept: */*", "Connection: close","User-Agent: VerniyXYZ-CURL" ,"Content-Transfer-Encoding: binary",
													"Content-Type: application/x-www-form-urlencoded;charset=UTF-8", 
													"Content-Length: $msg_len", "Host: upload.twitter.com",		
		'Authorization: OAuth oauth_consumer_key="' . $this->oauth_data["oauth_consumer_key"] .'",oauth_nonce="' . $random_value . '",oauth_signature="' .
			$signature . '",oauth_signature_method="' .$method . '"' . ',oauth_timestamp="' . $timestamp . '",oauth_token="' . $this->oauth_data["oauth_token"] . '",oauth_version="' . $oauth_version . '"'										
														);			
		
		//request		
		$curl = curl_init($this->media_api);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postfield_string);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$media_id_arr = json_decode(curl_exec($curl), true);
		print_r ($media_id_arr);
		return $media_id_arr["media_id_string"];
}

	function mediaAppend(&$binary_file, $media_id, $random_value, $timestamp){
		$command = "APPEND";
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
		
		$segment_index = 0;
			
		//header data
			  // BUILD SIGNATURE			
		$signature =  rawurlencode($this->generateSignature(array(
									"base_url" => $this->media_api,
									"request_method" => "POST"),
									array(
									"command" => "$command",
									"media" => "$binary_file",
									"media_id"=>"$media_id",
									"segment_index"=>"$segment_index",
									"oauth_version" => "$oauth_version",
									"oauth_nonce"=>"$random_value",
									"oauth_token"=> $this->oauth_data["oauth_token"],
									"oauth_timestamp" => "$timestamp",
									"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
									"oauth_signature_method" => "$method",
									),
									array(
									"consumer_secret" => $this->oauth_data["consumer_secret"],
									"oauth_secret" => $this->oauth_data["oauth_secret"]
									)
								));
										

		$postfield_string = "media=" . rawurlencode($binary_file) . "&command=$command&media_id=$media_id&segment_index=$segment_index" ;
		$msg_len = (strlen($postfield_string));
		$header_data = array("Except:", "Connection: close","User-Agent: VerniyXYZ-CURL" ,"Content-Transfer-Encoding: binary",
													"Content-Type: application/x-www-form-urlencoded", 
													"Content-Length: $msg_len", "Host: upload.twitter.com",
		'Authorization: OAuth oauth_consumer_key="' . $this->oauth_data["oauth_consumer_key"] .'",oauth_nonce="' . $random_value . '",oauth_signature="' .
			$signature . '",oauth_signature_method="' .$method . '"' . ',oauth_timestamp="' . $timestamp . '",oauth_token="' . $this->oauth_data["oauth_token"] . '",oauth_version="' . $oauth_version . '"'										
														);									
		//request
		$curl = curl_init($this->media_api);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postfield_string);
		curl_setopt($curl, CURLOPT_HEADER  , true);  
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$http_response = curl_exec($curl);
		echo $http_response;
	}

	function makeFinal($media_id, $random_value, $timestamp){
		$command = "FINALIZE";
		$method = "HMAC-SHA1";
		$oauth_version = "1.0";
		
		$signature =  rawurlencode($this->generateSignature(array(
								"base_url" => $this->media_api,
								"request_method" => "POST"),
								array(
								"command" => "$command",
								"media_id"=>"$media_id",
								"oauth_version" => "$oauth_version",
								"oauth_nonce"=>"$random_value",
								"oauth_token"=> $this->oauth_data["oauth_token"],
								"oauth_timestamp" => "$timestamp",
								"oauth_consumer_key" => $this->oauth_data["oauth_consumer_key"],
								"oauth_signature_method" => "$method",
								),
								array(
								"consumer_secret" => $this->oauth_data["consumer_secret"],
								"oauth_secret" => $this->oauth_data["oauth_secret"]
								)
							));
		$postfield_string = "command=$command&media_id=$media_id" ;
		$msg_len = (strlen($postfield_string));
		$header_data = array("Except:", "Connection: close","User-Agent: VerniyXYZ-CURL" ,"Content-Transfer-Encoding: binary",
													"Content-Type: application/x-www-form-urlencoded", 
													"Content-Length: $msg_len", "Host: upload.twitter.com",
		'Authorization: OAuth oauth_consumer_key="' . $this->oauth_data["oauth_consumer_key"] .'",oauth_nonce="' . $random_value . '",oauth_signature="' .
			$signature . '",oauth_signature_method="' .$method . '"' . ',oauth_timestamp="' . $timestamp . '",oauth_token="' . $this->oauth_data["oauth_token"] . '",oauth_version="' . $oauth_version . '"'										
														);									
		//request
		$curl = curl_init($this->media_api);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header_data);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postfield_string);	
		curl_setopt($curl, CURLOPT_HEADER  , true);  
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$http_response = curl_exec($curl);
		echo $http_response;			
	}

	function generateSignature($request_arr, $paramater_arr, $secret_arr){	
		  // BUILD SIGNATURE
		$request_method = strtoupper($request_arr["request_method"]);
		$base_url = rawurlencode($request_arr["base_url"]);
	
		ksort($paramater_arr);		
		if(isset($paramater_arr["media"])) $paramater_arr["media"] = rawurlencode($paramater_arr["media"]);
		$paramter_string = $this->buildOAuthParamaterString($paramater_arr); 	

		$base_string = ($request_method . "&" .  $base_url  . "&" . $paramter_string);									
		$secret_string = $secret_arr["consumer_secret"] . "&" . $secret_arr["oauth_secret"];
		$signature =  (base64_encode(hash_hmac("SHA1",$base_string, $secret_string, true)));	
			
		return $signature;	
	}
	
	function buildOAuthParamaterString($paramater_arr){
		$param_string = "";
		$join_param_by_amphersand = false;
		foreach($paramater_arr as $key => $param){
			if(!$join_param_by_amphersand){
				$join_param_by_amphersand=true;
			}
			else{
				$param_string .= rawurlencode("&");
			}
			$param_string .=  rawurlencode($key . "=" . $param);
		}
		return $param_string; 		
	}
	

}
	//echo"run script from externals<br/>";
?>