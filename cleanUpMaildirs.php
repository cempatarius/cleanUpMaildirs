#!/usr/bin/php
<?php
/******************************************************************************
    Copyright (C) 2013 Cempatarius

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*******************************************************************************/

/**
 * Where should the maildir(s) be moved when not inside SQL db?
 */
$backup_dir = '/backups/deleted_maildir/';

/**
 * Where are the current maidir(s) stored? (Check mail server settings)
 */
$maildir_base = '/var/spool/mail/';

/**
 * RDMS used? Only mysql valid in this version.
 */
$db_type = 'mysql';

/**
 * RDMS Auth settings.
 */
$db_user = 'sqluser';
$db_pass = 'sqlpass';

/**
 * RDMS Host settings.
 */
$db_host = 'localhost';
$db_base = 'maildb';

/**
 * RDMS Table Settings.
 */
$db_table = 'mailbox';
$db_col   = 'maildir';

/**
 * Compression
 */
$compress = true;

/**
 * Checksum
 */
$checksum = true;

/**
 * Timestamp
 */
$timestamp = true;

/**
 * Verbose output - Can generate a large amount of output.
 */
$verbose = true;

/**
 * Don't actually process the results, just output what needs moved.
 */
$dryrun = false;

###############################################################################
#                      DO NOT EDIT AFTER THIS POINT!!!                        #
###############################################################################

###############################################################################
#                                 FUNCTIONS                                   #
###############################################################################

/**
 * Returns an array of maildirs on the fs
 */
function fs_maildir_array($maildir_base) {
  foreach(glob($maildir_base.'*/*/', GLOB_ONLYDIR) as $maildir) {
    $fs_maildir[] = $maildir;
  }
  sort($fs_maildir);
  return $fs_maildir;
}

/**
 * Moves, compresses, checksum and timestamp old maildirs.
 */
function move_maildirs($dir_array,$compress,$checksum,$timestamp,$backup_dir) {
  foreach($dir_array as $dir) {
    $dir_name = substr(strrchr(substr($dir,0,-1),'/'),1);
    $date = date('mdY');
    if($timestamp) {
      if($compress) {
        $filename = $dir_name.'-'.$date.'.tar.gz';
      } else {
        $filename = $dir_name.'-'.$date.'.tar';
      }
    }
    if($checksum) {
      exec('find '.$dir.' -type f -print0 | xargs -0 md5sum >> '.$dir.'files-checksum.md5', $md5_files_output);
      $master_output_array[$dir_name]['checksum_files'] = $md5_files_output;
    }
    if($compress) {
      exec('tar -czvf '.$backup_dir.$filename.' '.$dir, $tar_output);
    } else {
      exec('tar -cvf '.$backup_dir.$filename.' '.$dir, $tar_output);
    }
    $master_output_array[$dir_name]['tar_output'] = $tar_output;
    if($checksum) {
      exec('md5sum '.$backup_dir.$filename.' > '.$backup_dir.$filename.'.md5', $md5_output);
      $master_output_array[$dir_name]['checksum_tar'] = $md5_output;
    }
    exec('rm -rfv '.$dir, $rm_output);
    $master_output_array[$dir_name]['rm_output'] = $rm_output;
  }

  return $master_output_array;
}

/**
 * Compares two arrays and finds the ones that are missing from database.
 */
function compare_arrays($db_maildir,$fs_maildir) {
  $maildir_diff = array_diff($fs_maildir,$db_maildir);
  return $maildir_diff;
}

/**
 * Check if user is root
 */
function whoami() {
  exec('id -u', $who);
  if($who[0] != '0') {
    return false;
  } else {
    return true;
  }
}


###############################################################################
#                                   START                                     #
###############################################################################

/**
 * Exit if not root
 */
if(!whoami()) {
  echo 'You must be root to execute script';
  exit(1);
}

/**
 * Connect to mysql
 */
if($db_type == 'mysql') {
  $mysqli = new mysqli($db_host,$db_user,$db_pass,$db_base);
  if($mysqli->error) {
    echo $mysqli->error;
    exit(1);
  }
}

/**
 * Query DB and pull valid maildirs and create array
 */
$db_result = $mysqli->query('SELECT '.$db_col.' FROM '.$db_table);
if($mysqli->error) {
  echo $mysqli->error;
  mysqli_close($mysqli);
  exit(1);
}
while($row = $db_result->fetch_assoc()) {
  $db_maildir[] = $maildir_base.$row['maildir'];
}

/**
 * Fetch maildirs on fs
 */
$fs_maildir = fs_maildir_array($maildir_base);

/**
 * Compare both arrays to find the FS maildirs that need moved
 */
if(!empty($db_maildir) && !empty($fs_maildir)) {
  $move_list = compare_arrays($db_maildir,$fs_maildir);
} else {
  echo 'One/Both of the arrays where empty';
  mysql_close($mysqli);
  exit(1);
}

/**
 * If nothing needs moved exit clean
 */
if(empty($move_list)) {
  exit(0);
}

/**
 * If verbose is enabled echo current arrays
 */
if($verbose) {
  echo "Maildirs in DB\n";
  echo "==============\n";
  print_r($db_maildir);
  echo "\nMaildirs on FS\n";
  echo "==============\n";
  print_r($fs_maildir);
  echo "\nTo be moved\n";
  echo "===========\n";
  print_r($move_list);
  echo "\n";
}

/**
 * Exit without processing.
 */
if($dryrun) {
 exit(0);
}

/**
 * Move the needed maildirs
 */
$move_maildirs = move_maildirs($move_list,$compress,$checksum,$timestamp,$backup_dir);

if($verbose) {
  echo "Move Output\n";
  echo "===========\n";
  print_r($move_maildirs);
}

exit(0);

?>
