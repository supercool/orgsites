<?php
if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('TDOMF: You are not allowed to call this page directly.'); }

///////////////////////
// Upload Files Core //
///////////////////////

// 1. User uploads files to a temporary area. Files will be deleted within an
//    hour if not "claimed"
// 2. User submits post. 
// 3. Widget copies the files from a temporary area to their proper location and
//    updates post with info about claimed files.
// 
// * If post is deleted, files are automatically deleted
// * No direct links to files are exposed (as long as the admins specify a 
//   location not directly exposed to the web)

// Figure out the storage path for this user/ip and thusly create it
//
function tdomf_create_tmp_storage_path($form_id = 1) {
  global $current_user;
  $options = tdomf_widget_upload_get_options($form_id); 
  get_currentuserinfo();
  if(is_user_logged_in()) {  
    $storagepath = $options['path'].DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$form_id.DIRECTORY_SEPARATOR.$current_user->user_login;
  } else {
    $ip =  $_SERVER['REMOTE_ADDR'];
    $storagepath = $options['path'].DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$form_id.DIRECTORY_SEPARATOR.$ip;
  }
  if(!file_exists($storagepath)) {
    tdomf_log_message("$storagepath does not exist. Creating it.");
    #mkdir($storagepath,'0777',true); <-- the permissions do not get set correctly with this method
    tdomf_recursive_mkdir($storagepath,TDOMF_UPLOAD_PERMS);
  } 
  return realpath($storagepath);
}

// Turn file size in bytes to an intelligable format 
// Taken from http://www.phpriot.com/d/code/strings/filesize-format/index.html
//
function tdomf_filesize_format($bytes, $format = '', $force = '')
    {
        $force = strtoupper($force);
        $defaultFormat = '%01d %s';
        if (strlen($format) == 0)
            $format = $defaultFormat;
 
        $bytes = max(0, (int) $bytes);
 
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
 
        $power = array_search($force, $units);
 
        if ($power === false)
            $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
 
        return sprintf($format, $bytes / pow(1024, $power), $units[$power]);
    }

// Delete a temp file. Function used to clean out upload files after 1 hour.
//
function tdomf_delete_tmp_file($filepath) {
  tdomf_log_message_extra("tdomf_delete_tmp_file for $filepath");
  if(file_exists($filepath)) {
     tdomf_log_message("Attempting to delete $filepath...");
     if(unlink($filepath)) {
       tdomf_log_message("Deleted $filepath!");
     } else {
       tdomf_log_message("Could not delete $filepath",TDOMF_LOG_ERROR);
     }
  }
}
add_action( 'tdomf_delete_tmp_file_hook', 'tdomf_delete_tmp_file' );

// Download handler
//
function tdomf_upload_download_handler(){
   global $current_user,$post_meta_cache,$blog_id;
   $post_ID = $_GET['tdomf_download'];
   $file_ID = $_GET['id'];
   $use_thumb = isset($_GET['thumb']);
   
   // Security check
   get_currentuserinfo();   
   if(!current_user_can("publish_posts")) {
     $post = get_post($post_ID);
     if($post->post_status != 'publish') {
       return;
     }
   }

   // For some reason, the post meta value cache does not include private 
   // keys (those starting with _) so unset it and update it properly!
   //
   unset($post_meta_cache[$blog_id][$post_ID]);
   update_postmeta_cache($post_ID);

   if($use_thumb) {
      $filepath = get_post_meta($post_ID, TDOMF_KEY_DOWNLOAD_THUMB.$file_ID, true);
      // a previous version of TDOMF did not properly define 
      // TDOMF_KEY_DOWNLOAD_THUMB so it used "TDOMF_KEY_DOWNLOAD_THUMB" as the 
      // actually key, so double check here, just in case.
      if(!file_exists($filepath)) {
        tdomf_log_message("The key ".TDOMF_KEY_DOWNLOAD_THUMB."$file_ID is not defined on $post_ID. Attempting to use ".'TDOMF_KEY_DOWNLOAD_THUMB'."$file_ID!",TDOMF_LOG_BAD);
        $filepath = get_post_meta($post_ID, 'TDOMF_KEY_DOWNLOAD_THUMB'.$file_ID, true);
      }
   } else {
      $filepath = get_post_meta($post_ID, TDOMF_KEY_DOWNLOAD_PATH.$file_ID, true);
   }
   if(!empty($filepath)) {

     if(!$use_thumb) {
        $type = get_post_meta($post_ID, TDOMF_KEY_DOWNLOAD_TYPE.$file_ID, true);
     }
     $name = get_post_meta($post_ID, TDOMF_KEY_DOWNLOAD_NAME.$file_ID, true);

     // Check if file exists
     //
     if(file_exists($filepath)) {
       
       @ignore_user_abort();
       @set_time_limit(600);
       if(!empty($type)) {
          $mimetype = $type;
       } else if(function_exists('mime_content_type')) { // set mime-type
          $mimetype = mime_content_type($filepath);
       } else {
          // default
          $mimetype = 'application/octet-stream';         
       }
      
       if(!$use_thumb) {       

       // Other stuff we could track...
       //
       //$referer = $_SERVER['HTTP_REFERER'];
       //ip = $_SERVER['REMOTE_ADDR'];
       //$now = date('Y-m-d H:i:s');

       // Update count
       //
       // This includes partial downloads! If wanted only full downloads
       // we would track it afterwards
       //
       $count = intval(get_post_meta($post_ID, TDOMF_KEY_DOWNLOAD_COUNT.$file_ID, true));
       $count++;
       update_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_COUNT.$file_ID,$count);
       
       }

       // Pass file       
       $handle = fopen($filepath, "rb"); // now let's get the file!
       #header("Pragma: "); // Leave blank for issues with IE
       #header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
       header("Content-Type: $mimetype");
       #header("Content-Disposition: attachment; filename=\"".basename($filepath)."\"");
       header("Content-Length: " . (filesize($filepath)));
       sleep(1);
       fpassthru($handle);
       return;
     } else {
       tdomf_log_message("File $filepath does not exist!",TDOMF_LOG_ERROR);
     }
   } else {
     if($use_thumb) {
       tdomf_log_message("No thumb found on post with id $post_ID!",TDOMF_LOG_ERROR);
     } else {
       tdomf_log_message("No file found on post with id $post_ID!",TDOMF_LOG_ERROR);
     }
     tdomf_log_message("Post Meta Cache for $post_ID on $blog_id <pre>".var_export($post_meta_cache[$blog_id][$post_ID],true)."</pre>",TDOMF_LOG_BAD);
   }
   header("HTTP/1.0 404 Not Found");
   exit();
}
if(isset($_GET['tdomf_download'])) { 
  add_action('init', 'tdomf_upload_download_handler');
}

// Create path recursivily
// Which is a disaster when safe_mode is enabled. A simple function gone insane!
//
function tdomf_recursive_mkdir($path, $mode = 0777) {
    $path = trim($path);
    
    // TODO For versions > PHP 5.1.6, a trailing slash in mkdir causes problems!
    
    #clearstatcache();
    if(@is_dir($path)) {
      tdomf_log_message("$path exists");
      return true;
    }
    
    // A full windows path uses ":" compared to unix
    if(eregi(':', $path)) {
      $isWin = true;
    }
    
    $dirs = explode(DIRECTORY_SEPARATOR , $path);
    $count = count($dirs);
    $path = '';
    $prevpath = '';
    for ($i = 0; $i < $count; ++$i) {
      // store previous path
      $prevpath = $path;
      if($i == 0 && $isWin) {
        // if windows, do not insert a SLASH for the first directory
        // "\c:\\" is an invalid path in Windows
        // -- thanks to "feelexit" on the TDOMF forums for fix
        $path .= $dirs[$i];
      } else { 
        $path .= DIRECTORY_SEPARATOR . $dirs[$i];
      }
      
      // sometimes double slashes get added to path (differences between PHP4 
      // and PHP5 and BSD systems etc.) and cause problems with open_basedir 
      // matching and other things. Might as well fix it here.
      // 
      $path = ereg_replace("//","/",$path);
      
      if(!@is_dir($path) && $path != "/" ) {
        tdomf_log_message("Attempting to create directory $path");
        
        if(get_option(TDOMF_OPTION_EXTRA_LOG_MESSAGES)) {
          // Some debug code to check for safe_mode compatibility, only enabled
          // if option is enabled!
          
          // about to create directory (that's not root), check safe mode 
          // for debugging only - no fix here!
          if( $i > 0 && ini_get('safe_mode') ){
  
            // only check gid or uid if path not in include dir (if include dir
            // is set of course)
            $check_gid = true;
            if( ini_get('safe_mode_include_dir') != NULL ){
              $include_dirs = ini_get('safe_mode_include_dir');
              if($isWin) {
                $include_dirs = split(";",$include_dirs);
              } else {
                $include_dirs = split(":",$include_dirs);
              }
              if(!empty($include_dirs)) {
                foreach($include_dirs as $inc_dir){
                  // safe_mode_include_dir is actually just a prefix
                  if( substr($prevpath, 0, strlen($inc_dir)) == $inc_dir) {
                    tdomf_log_message("$prevpath matches a path in safe_mode_include_dir: " + $inc_dir, TDOMF_LOG_GOOD);
                    $check_gid = false;
                  }
                }
              }
              if($check_gid) {
                tdomf_log_message("$prevpath does not match any path in safe_mode_include_dir: " + ini_get('safe_mode_include_dir'), TDOMF_LOG_BAD);
              }
            }
            if($check_gid) {
              // gid or uid
              if( ini_get('safe_mode_gid') ){
                $myid = @getmygid();
                $myid_posix = @posix_getgid();
                $pathid = @filegroup($prevpath);
                // log message
                if($pathid != $myid){
                  tdomf_log_message("Safe Mode Enabled: May not be able to create path $path because $prevpath has gid $pathid. This script has gid $myid", TDOMF_LOG_BAD);
                }
                if($pathid != $myid_posix){
                  tdomf_log_message("Safe Mode Enabled: May not be able to create path $path because $prevpath has gid $pathid. This process has gid $myid_posix", TDOMF_LOG_BAD);
                }
              } else {
                $myid = @getmyuid();
                $myid_posix = @posix_getuid();
                $pathid = @fileowner($prevpath);
                // log message
                if($pathid != $myid){
                  tdomf_log_message("Safe Mode Enabled: May not be able to create path $path because $prevpath has uid $pathid. This script has uid $myid", TDOMF_LOG_BAD);
                }
                if($pathid != $myid_posix){
                  tdomf_log_message("Safe Mode Enabled: May not be able to create path $path because $prevpath has uid $pathid. This process has uid $myid_posix", TDOMF_LOG_BAD);
                }
              }
            }
            
          }
          
          // check open_basedir (seperate to safe_mode)
          if( ini_get('open_basedir') != NULL ){
            $open_basedir_match = false;
            $op_dirs = ini_get('open_basedir');
            if($isWin) {
              $op_dirs = split(";",$op_dirs);
            } else {
              $op_dirs = split(":",$op_dirs);
            }
            if(!empty($op_dirs)) {
              foreach($op_dirs as $inc_dir){
                // open_basedir is actually just a prefix
                if( substr($prevpath, 0, strlen($inc_dir)) == $inc_dir) {
                  tdomf_log_message("$prevpath matches a path in open_basedir: " + $inc_dir, TDOMF_LOG_GOOD);
                  $check_gid = false;
                }
              }
            }
            if($check_gid) {
              tdomf_log_message("$prevpath does not match any path in open_basedir: " + ini_get('open_basedir'), TDOMF_LOG_BAD);
            }
          }
        }
      } 
      else {
        tdomf_log_message_extra("Looking at $path");
        if(@is_link($path)) {
          tdomf_log_message_extra("$path is a symbolic link");
        }
      }
      
      // In safe_mode, is_dir may return false for a valid path. So, if in 
      // safe_mode and is_dir returns false, try and create directory but 
      // ignore and suppress errors
      //
      if(ini_get('safe_mode') || ini_get('open_basedir')) {
        if(!@is_dir($path)) {
          @mkdir(trim($path), $mode);
        }
      } else {
        // Not in safe mode, is_dir should work all the time. Therefore 
        // break out if mkdir fails!
        if (!@is_dir($path) && !@mkdir(trim($path), $mode)) {
            tdomf_log_message("Error when attempting to create $path!", TDOMF_LOG_ERROR);
            return false;
        }
        // use real path (only if we are pretty certain it won't break)
        $path = @realpath($path);
      }
    }
    
    if(@is_dir($path)) {
      tdomf_log_message("The directory $path was successfully created!", TDOMF_LOG_GOOD);
    } else {
      tdomf_log_message("The directory $path was not created!", TDOMF_LOG_BAD);
    }
    
    return true;
}

// Preview handler
//
function tdomf_upload_preview_handler(){
   $id = $_GET['tdomf_upload_preview'];
   $form_id = intval($_GET['form']);
   $form_data = tdomf_get_form_data($form_id); 
   
   $tdomf_verify = get_option(TDOMF_OPTION_VERIFICATION_METHOD);
   if($tdomf_verify == false || $tdomf_verify == 'default') {
     $key = $_GET['key'];
      if($form_data['tdomf_upload_preview_key_'.$form_id] != $key) {
        return;
      }
   } else if($tdomf_verify == 'wordpress_nonce' && 
             !wp_verify_nonce($_GET['key'],'tdomf-form-upload-preview-'.$form_id)) {
    return;
  }
   
   if(!isset($form_data['uploadfiles_'.$form_id][$id])) {
     tdomf_log_message("(preview) No file with that id! $id",TDOMF_LOG_ERROR);
     return;
   }
      
   $filepath = $form_data['uploadfiles_'.$form_id][$id]['path'];
   if(!empty($filepath)) {

     $type = $form_data['uploadfiles_'.$form_id][$id]['type'];
     
     // Check if file exists
     //
     if(file_exists($filepath)) {
       
       @ignore_user_abort();
       @set_time_limit(600);
       if(function_exists('mime_content_type')) { // set mime-type
          $mimetype = mime_content_type($filepath);
       } else if(!empty($type)) {
          $mimetype = $type;
       } else {
          // default
          $mimetype = 'application/octet-stream';         
       }
      
       // Pass file       
       $handle = fopen($filepath, "rb"); // now let's get the file!
       #header("Pragma: "); // Leave blank for issues with IE
       #header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
       header("Content-Type: $mimetype");
       #header("Content-Disposition: attachment; filename=\"".basename($filepath)."\"");
       header("Content-Length: " . (filesize($filepath)));
       sleep(1);
       fpassthru($handle);
       return;
     } else {
       tdomf_log_message("(preview) File $filepath does not exist!",TDOMF_LOG_ERROR);
     }
   } else {
     tdomf_log_message("(preview) No file found on post with that id!",TDOMF_LOG_ERROR);
   }
   header("HTTP/1.0 404 Not Found");
   exit();
}
if(isset($_GET['tdomf_upload_preview'])) { 
  add_action('init', 'tdomf_upload_preview_handler');
}

// Delete a folder and contents
// Taken from http://ie2.php.net/manual/en/function.rmdir.php
//
function tdomf_deltree($path) {
  if (@is_dir($path) && !@is_link($path)) {
     if(function_exists('scandir')) {
        $entries = scandir($path);
     } else {
        // PHP 4 version
        $dh  = opendir($path);
        while (false !== ($filename = readdir($dh))) {
           $entries[] = $filename;
        }
     }
    foreach ($entries as $entry) {
      if ($entry != '.' && $entry != '..') {
        tdomf_deltree($path.DIRECTORY_SEPARATOR.$entry);
      }
    }
    rmdir($path);
  } else {
    unlink($path);
  }
}

// Delete files associated with a post when a post is deleted
//
function tdomf_upload_delete_post_files($post_ID) {
  // get first file, if it exists. Get directory. Delete directory and contents.
  $filepath = get_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_PATH.'0',true);
  
 // A full windows path uses ":" compared to unix
  if(eregi(':', $filepath)) {
      // if it's a full windows path, check to see if it contains '\' or '/'
      if(strpos('\\', $path) === false && strpos('/', $path) === false) {
          tdomf_log_message("Invalid windows path: $filepath - do nothing. Files will have to be deleted manually for deleted post $post_ID.",TDOMF_LOG_ERROR);
          return;
      }
  }
  
  // 
  $dirpath = dirname($filepath);
  if(file_exists($dirpath)) {
    tdomf_deltree($dirpath);
  }
}
add_action('delete_post', 'tdomf_upload_delete_post_files');

////////////////////////////////////////////////////////////////////////////////
//                                           Default Widget: "Upload Files"   //
////////////////////////////////////////////////////////////////////////////////

// Required for creating images using attachments
//
include_once(ABSPATH . 'wp-admin/includes/admin.php');

// Get Options for this widget
//
function tdomf_widget_upload_get_options($form_id) {
  $options = tdomf_get_option_widget('tdomf_upload_widget',$form_id);
    if($options == false) {
       $options = array();
       $options['title'] = '';
       $options['path'] = ABSPATH.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR;
       $options['types'] = ".txt .doc .pdf .jpg .gif .zip";
       $options['size'] = 1048576;
       $options['min'] = 0;
       $options['max'] = 1;
       $options['cmd'] = "";
       $options['attach'] = true;
       $options['a'] = true;
       $options['img'] = false;
       $options['custom'] = true;
       $options['custom-key'] = __("Download Link","tdomf");
       $options['post-title'] = false;
       $options['attach-a'] = false;
       $options['attach-thumb-a'] = false;
       $options['thumb-a'] = false;
       $options['url'] = trailingslashit(get_bloginfo('wpurl')).'wp-content/uploads/';
       $options['nohandler'] = true;
    }
    if(!isset($options['url'])){ $options['url'] = trailingslashit(get_bloginfo('wpurl')).'wp-content/uploads/'; }
  return $options;
}

//////////////////////////////
// Display the widget! 
//
function tdomf_widget_upload($args) {
  extract($args);
  $options = tdomf_widget_upload_get_options($tdomf_form_id);
  
  $output  = $before_widget;  
  if($options['title'] != "") {
    $output .= $before_title.$options['title'].$after_title;
  }
  $inline_path = TDOMF_URLPATH."tdomf-upload-inline.php"."?tdomf_form_id=".$tdomf_form_id;
  // my best guestimate
  $height = 160 + (intval($options['max']) * 30);
  $output .= "<iframe id='uploadfiles_inline' name='uploadfiles_inline' frameborder='0' marginwidth='0' marginheight='0' width='100%' height='$height' src='$inline_path'></iframe>";
  $output .= $after_widget;
  return $output;
}
tdomf_register_form_widget('upload-files','Upload Files', 'tdomf_widget_upload', $modes = array('new'));

//////////////////////////////
// Post-post stuff
//
// Post is submitted, move files to correct area and update post with links 
//
function tdomf_widget_upload_post($args) {
  global $wpdb;
  extract($args);
  $options = tdomf_widget_upload_get_options($tdomf_form_id);
  $form_data = tdomf_get_form_data($tdomf_form_id);
  
  $modifypost = false;
  if($options['post-title'] ||
     $options['a'] || 
     $options['img'] || 
     $options['attach-a'] ||
     $options['attach-thumb-a'] ||
     $options['thumb-a']
     ) {
    // Grab existing data
    $post = wp_get_single_post($post_ID, ARRAY_A);
    if(!empty($post['post_content'])) {
         $post = add_magic_quotes($post);
    }
    $content = $post['post_content'];
    $title = $post['post_title'];
    $cats = $post['post_category'];
  }
  
  $filecount = 0;
  $theirfiles = $form_data['uploadfiles_'.$tdomf_form_id];
  for($i =  0; $i < $options['max']; $i++) {
    if(!file_exists($theirfiles[$i]['path'])) {
      unset($theirfiles[$i]);
    } else {
      $filecount++;
      // move file
      $postdir = $options['path'].DIRECTORY_SEPARATOR.$post_ID;
      tdomf_recursive_mkdir($postdir,TDOMF_UPLOAD_PERMS);
      $newpath = $postdir.DIRECTORY_SEPARATOR.$theirfiles[$i]['name'];
      if(rename($theirfiles[$i]['path'], $newpath)) {
        
        $newpath = realpath($newpath);
        
        // store info about files on post
        //        
        add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_COUNT.$i,0,true);
        add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_TYPE.$i,$theirfiles[$i]['type'],true);
        // escape the "path" incase it contains '\' as WP will strip these out!
        add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_PATH.$i,$wpdb->escape($newpath),true);
        add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_NAME.$i,$theirfiles[$i]['name'],true);
        
        tdomf_log_message( "File ".$theirfiles[$i]['name']." saved from tmp area to ".$newpath." with type ".$theirfiles[$i]['type']." for post $post_ID" );
        
        // Execute user command
        //
        if($options['cmd'] != "") {
          $cmd_output = shell_exec ( $options['cmd'] . " " . $newpath );
          tdomf_log_message("Executed user command on file $newpath<br/><pre>$cmd_output</pre>");
          add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_CMD_OUTPUT.$i,$cmd_output,true);
        }
        
        // Use direct links or wrapper
        //
        if($options['nohandler'] && trim($options['url']) != "") {
          $uri = trailingslashit($options['url'])."$post_ID/".$theirfiles[$i]['name'];
        } else {
          $uri = trailingslashit(get_bloginfo('wpurl')).'?tdomf_download='.$post_ID.'&id='.$i;
        }
        
        // Modify Post
        //
        
        // modify post title
        if($options['post-title']) {
          $modifypost = true;
          $title = $theirfiles[$i]['name'];
        }
        // add download link (inc name and file size)
        if($options['a']) {
          $modifypost = true;
          $content .= "<p><a href=\"$uri\">".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")</a></p>";
        }
        // add image link (inc name and file size)
        if($options['img']) {
          $modifypost = true;
          $content .= "<p><img src=\"$uri\" /></p>";
        }
        
        // Use user-defined custom key 
        if($options['custom'] && !empty($options['custom-key'])) {
          add_post_meta($post_ID,$options['custom-key'],$uri);
        }

        // Insert upload as an attachment to post!
        if($options['attach']) {
          
          // Create the attachment (not sure if these values are correct)
          //
          $attachment = array (
           "post_content"   => "",
           "post_title"     => $theirfiles[$i]['name'],
           "post_name"      => sanitize_title($theirfiles[$i]['name']),
           "post_status"    => 'inherit',
           "post_parent"    => $post_ID,
           "guid"           => $uri,
           "post_type"      => 'attachment',          
           "post_mime_type" => $theirfiles[$i]['type'],
           "menu_order"     => $i,
           "post_category"  => $cats,
          );
          $attachment_ID = wp_insert_attachment($attachment, $newpath, $post_ID);
          
          // Generate meta data (which includes thumbnail!)
          // 
          $attachment_metadata = wp_generate_attachment_metadata( $attachment_ID, $newpath );

          // add link to attachment page
          if($options['attach-a']) {
            $content .= "<p><a href=\"".get_permalink($attachment_ID)."\">".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")</a></p>";
          }
          
          if(tdomf_wp23()) {
            // Did Wordpress generate a thumbnail?
            if(isset($attachment_metadata['thumb'])) {
               // Wordpress 2.3 uses basename and generates only the "name" of the thumb,
               // in general it creates it in the same place as the file!
               $thumbpath = $postdir.DIRECTORY_SEPARATOR.$attachment_metadata['thumb'];
               if(file_exists($thumbpath)) {
                  
                  add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_THUMB.$i,$thumbpath,true);
                  
                  // WARNING: Don't modify the 'thumb' as this is used by Wordpress to know
                  // if there is a thumb by using basename and the file path of the actual file
                  // attachment
                  //
                  // Use direct links *or* wrapper
                  //
                  if($options['nohandler'] && trim($options['url']) != "") {
                    $thumburi = $options['url']."/$post_ID/".$attachment_metadata['thumb'];
                  } else {
                    $thumburi = get_bloginfo('wpurl').'/?tdomf_download='.$post_ID.'&id='.$i.'&thumb';
                  }
                  
                  // store a copy of the thumb uri
                  add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_THUMBURI.$i,$thumburi,true);
                  
                  //$attachment_metadata['thumb'] = $thumb_uri;
                  //$attachment_metadata['thumb'] = $thumbpath;
                  
                  // add thumbnail link to attachment page
                  if($options['attach-thumb-a']) {
                    $modifypost = true;
                    $content .= "<p><a href=\"".get_permalink($attachment_ID)."\"><img src=\"$thumburi\" alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></a></p>";
                  }
                  // add thumbnail link directly to file
                  if($options['thumb-a']) {
                    $modifypost = true;
                    $content .= "<p><a href=\"$uri\"><img src=\"$thumburi\" alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></a></p>";
                  }
               } else {
                  tdomf_log_message("Could not find thumbnail $thumbpath!",TDOMF_LOG_ERROR);
               }
            }
          } else {
            
            // In Wordpress 2.5 the attachment data structure is changed, 
            // it only generates a thumbnail if it needs to...
            if(isset($attachment_metadata['sizes']['thumbnail'])) {
              // btw there also seems to be a "medium" size sometimes generated
              $thumbpath = $postdir.DIRECTORY_SEPARATOR.$attachment_metadata['sizes']['thumbnail']['file'];
              if(file_exists($thumbpath)) {
                  
                  add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_THUMB.$i,$thumbpath,true);
                  
                  // Use direct links *or* wrapper
                  //
                  if($options['nohandler'] && trim($options['url']) != "") {
                    $thumburi = $options['url']."/$post_ID/".$attachment_metadata['sizes']['thumbnail']['file'];
                  } else {
                    $thumburi = get_bloginfo('wpurl').'/?tdomf_download='.$post_ID.'&id='.$i.'&thumb';
                  }
                  
                  // store a copy of the thumb uri
                  add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_THUMBURI.$i,$thumburi,true);
                  
                  // add thumbnail link to attachment page
                  if($options['attach-thumb-a']) {
                    $modifypost = true;
                    $content .= "<p><a href=\"".get_permalink($attachment_ID)."\"><img src=\"$thumburi\" alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></a></p>";
                  }
                  // add thumbnail link directly to file
                  if($options['thumb-a']) {
                    $modifypost = true;
                    $content .= "<p><a href=\"$uri\"><img src=\"$thumburi\" alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></a></p>";
                  }
               } else {
                  tdomf_log_message("Could not find thumbnail $thumbpath!",TDOMF_LOG_ERROR);
               }
            } else if(wp_attachment_is_image($attachment_ID) && ($options['attach-thumb-a'] || $options['thumb-a'])) {
              // Thumbnail not generated automatically, this means that the image
              // is smaller than a thumbnail => use as thumbnail

              tdomf_log_message("No thumbnail created => image is too small. Use image as thumbnail.",TDOMF_LOG_ERROR);
              
              $modifypost = true;
              
              $sizeit = "";
              $h = get_option("thumbnail_size_h");
              $w = get_option("thumbnail_size_w");
              if($attachment_metadata['height'] > $h || $attachment_metadata['width'] > $w) { 
                if($attachment_metadata['height'] > $attachment_metadata['width']) {
                  $sizeit = " height=\"${h}px\" "; 
                } else {
                  $sizeit = " height=\"${w}px\" ";
                }
              }
              
              // store a the uri as a the thumburi
              add_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_THUMBURI.$i,$uri,true);
              
              // add thumbnail link to attachment page
              if($options['attach-thumb-a']) {
                 $content .= "<p><a href=\"".get_permalink($attachment_ID)."\"><img src=\"$uri\" $sizeit alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></a></p>";
              }
              // add just the image (no point linking to thumbnail)
              if($options['thumb-a']) {
                 $content .= "<p><img src=\"$uri\" $sizeit alt=\"".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($newpath)).")\" /></p>";
              }
            }
            
          }
          
          // Add meta data
          // 
          wp_update_attachment_metadata( $attachment_ID, $attachment_metadata );

          tdomf_log_message("Added " . $theirfiles[$i]['name'] . " as attachment");
        }
        
      } else {
        tdomf_log_message("Failed to move " . $theirfiles[$i]['name'] . "!",TDOMF_LOG_ERROR);
        return $before_widget.__("Failed to move uploaded file from temporary location!","tdomf").$after_widget;
      }
    }
  }
  
  if($modifypost) {
    tdomf_log_message("Attempting to update post with file upload info");
    $post = array (
      "ID"                      => $post_ID,
      "post_content"            => $content,
      "post_title"              => $title,
      "post_name"               => sanitize_title($title),
    );
    sanitize_post($post,"db");
    wp_update_post($post);
  }
 
  return NULL;
}
tdomf_register_form_widget_post('upload-files','Upload Files', 'tdomf_widget_upload_post', $modes = array('new'));

////////////////////////////////
// Validate uploads if possible
//
function tdomf_widget_upload_validate($args,$preview) {
  extract($args);
  $options = tdomf_widget_upload_get_options($tdomf_form_id);
  $form_data = tdomf_get_form_data($tdomf_form_id);

  if($options['min'] > 0 && !isset($form_data['uploadfiles_'.$tdomf_form_id])) {
    return $before_widget.sprintf(__("No files have been uploaded yet. You must upload a minimum of %d files.","tdomf"),$options['min']).$after_widget;
  }
  $theirfiles = $form_data['uploadfiles_'.$tdomf_form_id];
  $filecount = 0;
  for($i =  0; $i < $options['max']; $i++) {
    if(!file_exists($theirfiles[$i]['path'])) {
      unset($theirfiles[$i]);
    } else {
      $filecount++;
    }
  }
  if($filecount < $options['min']) {
    return $before_widget.sprintf(__("You must upload a minimum of %d files.","tdomf"),$options['min']).$after_widget;
  }
  return NULL;
}
tdomf_register_form_widget_validate('upload-files','Upload Files', 'tdomf_widget_upload_validate', $modes = array('new'));

//////////////////////////////
// Preview uplaods if possible
//
function tdomf_widget_upload_preview($args) {
  extract($args);
  $options = tdomf_widget_upload_get_options($tdomf_form_id);
  $form_data = tdomf_get_form_data($tdomf_form_id);

  // preview key
  //
  $tdomf_verify = get_option(TDOMF_OPTION_VERIFICATION_METHOD);
  if($tdomf_verify == 'wordpress_nonce' && function_exists('wp_create_nonce')) {
     $nonce_string = wp_create_nonce( 'tdomf-form-upload-preview-'.$tdomf_form_id );
     $form_data["tdomf_upload_preview_key_".$tdomf_form_id] = $nonce_string;
  } else if($tdomf_verify == 'none') {
     unset($form_data["tdomf_upload_preview_key_".$tdomf_form_id]);
  } else {
     $upload_key = tdomf_random_string(100);
     $form_data["tdomf_upload_preview_key_".$tdomf_form_id] = $upload_key;
  }
  tdomf_save_form_data($tdomf_form_id,$form_data);
 
  $output = $before_widget;
  $theirfiles = $form_data['uploadfiles_'.$tdomf_form_id];
  for($i =  0; $i < $options['max']; $i++) {
    if(file_exists($theirfiles[$i]['path'])) {
      if(isset($form_data["tdomf_upload_preview_key_".$tdomf_form_id])) {
         $uri = get_bloginfo('wpurl').'/?tdomf_upload_preview='.$i."&key=".$form_data["tdomf_upload_preview_key_".$tdomf_form_id]."&form=".$tdomf_form_id;
      } else {
         $uri = get_bloginfo('wpurl').'/?tdomf_upload_preview='.$i."&form=".$tdomf_form_id;
      }
      if($options['a']) {
        $output .= "<p><a href=\"$uri\">".$theirfiles[$i]['name']." (".tdomf_filesize_format(filesize($theirfiles[$i]['path'])).")</a></p>";
      }
      if($options['img']) {
        $output .= "<p><img src=\"$uri\" /></p>";
      }
    }
  }
  $output .= $after_widget;
  return $output;
}
tdomf_register_form_widget_preview('upload-files','Upload Files', 'tdomf_widget_upload_preview', $modes = array('new'));

////////////////////////////////////
// Add info on files to admin email 
//
function tdomf_widget_upload_adminemail($args) {
  extract($args);
  $options = tdomf_widget_upload_get_options($tdomf_form_id);
  
  $output = "";
  for($i =  0; $i < $options['max']; $i++) {
    $filepath = get_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_PATH.$i,true);
    if(file_exists($filepath)) {
      $name = get_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_NAME.$i,true);
      $uri = get_bloginfo('wpurl').'/?tdomf_download='.$post_ID.'&id='.$i;
      $size = tdomf_filesize_format(filesize($filepath));
      $cmd = get_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_CMD_OUTPUT.$i,true);
      $type = get_post_meta($post_ID,TDOMF_KEY_DOWNLOAD_TYPE.$i,true);
      $output .= sprintf(__("File %s was uploaded with submission.\r\nPath: %s\r\nSize: %s\r\nType: %s\r\nURL (can only be accessed by administrators until post published):\r\n%s\r\n\r\n","tdomf"),$name,$filepath,$size,$type,$uri);
      if($cmd != false && !empty($cmd)) {
        $output .= sprintf(__("User Command:\r\n\"%s %s\"\r\n\r\n%s\r\n\r\n","tdomf"),$options['cmd'],$filepath,$cmd);
      }
    }
  }
  if($output != "") {
    return $before_widget.$output.$after_widget;
  }
  return  $before_widget.__("No files uploaded with this post!","tdomf").$after_widget;
}
tdomf_register_form_widget_adminemail('upload-files','Upload Files', 'tdomf_widget_upload_adminemail', $modes = array('new'));

///////////////////////////////////////////////////
// Display and handle content widget control panel 
//
function tdomf_widget_upload_control($form_id) {
  $options = tdomf_widget_upload_get_options($form_id);
  
  // Store settings for this widget
  if ( $_POST['upload-files-submit'] ) {
      $newoptions = array();
      $newoptions['title'] = $_POST['upload-files-title'];
      $newoptions['path'] = $_POST['upload-files-path'];
      $newoptions['types'] = $_POST['upload-files-types'];
      $newoptions['size'] = intval($_POST['upload-files-size']);
      $newoptions['min'] = intval($_POST['upload-files-min']);
      $newoptions['max'] = intval($_POST['upload-files-max']);
      $newoptions['cmd'] = $_POST['upload-files-cmd'];
      $newoptions['attach'] = isset($_POST['upload-files-attach']);
      $newoptions['a'] = isset($_POST['upload-files-a']);
      $newoptions['img'] = isset($_POST['upload-files-img']);
      $newoptions['custom'] = isset($_POST['upload-files-custom']);
      $newoptions['custom-key'] = $_POST['upload-files-custom-key'];
      $newoptions['post-title'] = isset($_POST['upload-files-post-title']);
      $newoptions['attach-a'] = isset($_POST['upload-files-attach-a']);
      $newoptions['attach-thumb-a'] = isset($_POST['upload-files-attach-thumb-a']);
      $newoptions['thumb-a'] = isset($_POST['upload-files-thumb-a']);
      $newoptions['url'] = $_POST['upload-files-url'];
      $newoptions['nohandler'] = isset($_POST['upload-files-nohandler']);
      
      if ( $options != $newoptions ) {
        $options = $newoptions;
        tdomf_set_option_widget('tdomf_upload_widget', $options, $form_id);
     }
  }

   // Display control panel for this widget
  
        ?>
<p style="text-align:left;">

<label for="upload-files-title">
<?php _e("Title: ","tdomf"); ?>
<input type="textfield" id="upload-files-title" name="upload-files-title" value="<?php echo htmlentities($options['title'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label><br/><br/>

<label for="upload-files-path" ><?php _e("Path to store uploads (should not be publically accessible):","tdomf"); ?><br/>
<input type="textfield" size="40" id="upload-files-path" name="upload-files-path" value="<?php echo htmlentities($options['path'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label><br/><br/>

<label for="upload-files-types" ><?php _e("Allowed File Types:","tdomf"); ?><br/>
<input type="textfield" size="40" id="upload-files-types" name="upload-files-types" value="<?php echo htmlentities($options['types'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label><br/><br/>

<label for="upload-files-post-title">
<input type="checkbox" name="upload-files-post-title" id="upload-files-post-title" <?php if($options['post-title']) echo "checked"; ?> >
<?php _e("Use filename as post title (as long as the content widget doesn't set it)","tdomf"); ?>
</label><br/><br/>

<label for="upload-files-size">
<input type="textfield" name="upload-files-size" id="upload-files-size" value="<?php echo htmlentities($options['size'],ENT_QUOTES,get_bloginfo('charset')); ?>" size="10" />
<?php printf(__("Max File Size in bytes. Example: 1024 = %s, 1048576 = %s","tdomf"),tdomf_filesize_format(1024),tdomf_filesize_format(1048576)); ?> 
</label><br/><br/>

<input type="textfield" name="upload-files-min" id="upload-files-min" value="<?php echo htmlentities($options['min'],ENT_QUOTES,get_bloginfo('charset')); ?>" size="2" />
<label for="upload-files-min"><?php _e("Minimum File Uploads <i>(0 indicates file uploads optional)</i>","tdomf"); ?></label><br/>

<input type="textfield" name="upload-files-max" id="upload-files-max" value="<?php echo htmlentities($options['max'],ENT_QUOTES,get_bloginfo('charset')); ?>" size="2" />
<label for="upload-files-sax"><?php _e("Maximum File Uploads","tdomf"); ?></label><br/>


<br/>

<label for="upload-files-nohandler">
<input type="checkbox" name="upload-files-nohandler" id="upload-files-nohandler" <?php if($options['nohandler']) echo "checked"; ?> >
<?php _e("Do not use TDOMF handler for URL of download","tdomf"); ?>
</label><br/>

&nbsp;&nbsp;&nbsp;<label for="upload-files-url" ><?php _e("URL of uploaded file area:","tdomf"); ?><br/>
&nbsp;&nbsp;&nbsp;<input type="textfield" size="40" id="upload-files-url" name="upload-files-url" value="<?php echo htmlentities($options['url'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label>

<br/><br/>

<label for="upload-files-cmd" ><?php _e("Command to execute on file after file uploaded successfully (result will be added to log). Leave blank to do nothing:","tdomf"); ?><br/>
<input type="textfield" size="40" id="upload-files-cmd" name="upload-files-cmd" value="<?php echo htmlentities($options['cmd'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label><br/><br/>

<label for="upload-files-attach">
<input type="checkbox" name="upload-files-attach" id="upload-files-attach" <?php if($options['attach']) echo "checked"; ?> >
<?php _e("Insert Uploaded Files as Attachments on post (this will also generate a thumbnail using Wordpress core if upload is an image)","tdomf"); ?>
</label><br/>

&nbsp;&nbsp;&nbsp;

<label for="upload-files-attach-a">
<input type="checkbox" name="upload-files-attach-a" id="upload-files-attach-a" <?php if($options['attach-a']) echo "checked"; ?> >
<?php _e("Add link to Attachment page to post content","tdomf"); ?>
</label><br/>

&nbsp;&nbsp;&nbsp;

<label for="upload-files-attach-thumb-a">
<input type="checkbox" name="upload-files-attach-thumb-a" id="upload-files-attach-thumb-a" <?php if($options['attach-thumb-a']) echo "checked"; ?> >
<?php _e("Add thumbnail link to Attachment page to post content (if thumbnail avaliable)","tdomf"); ?>
</label><br/>

&nbsp;&nbsp;&nbsp;

<label for="upload-files-thumb-a">
<input type="checkbox" name="upload-files-thumb-a" id="upload-files-thumb-a" <?php if($options['thumb-a']) echo "checked"; ?> >
<?php _e("Add thumbnail as download link to post content (if thumbnail avaliable)","tdomf"); ?>
</label><br/>

       
<br/>

<label for="upload-files-a">
<input type="checkbox" name="upload-files-a" id="upload-files-a" <?php if($options['a']) echo "checked"; ?> >
<?php _e("Add download link to post content","tdomf"); ?>
</label><br/>

<label for="upload-files-img">
<input type="checkbox" name="upload-files-img" id="upload-files-img" <?php if($options['img']) echo "checked"; ?> >
<?php _e("Add download link as image tag to post content","tdomf"); ?>
</label><br/>

<br/>

<label for="upload-files-custom">
<input type="checkbox" name="upload-files-custom" id="upload-files-custom" <?php if($options['custom']) echo "checked"; ?> >
<?php _e("Add Download Link as custom value","tdomf"); ?>
</label><br/>

<label for="upload-files-custom-key" ><?php _e("Name of Custom Key:","tdomf"); ?><br/>
<input type="textfield" size="40" id="upload-files-custom-key" name="upload-files-custom-key" value="<?php echo htmlentities($options['custom-key'],ENT_QUOTES,get_bloginfo('charset')); ?>" />
</label>

</p>
        <?php 
}
tdomf_register_form_widget_control('upload-files','Upload Files', 'tdomf_widget_upload_control', 550, 750, $modes = array('new'));

?>
