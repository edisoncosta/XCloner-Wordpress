<?php
use splitbrain\PHPArchive\Tar;
use splitbrain\PHPArchive\Archive;
use splitbrain\PHPArchive\FileInfo;

class Xcloner_Archive extends Tar
{
	/*
	 * bytes
	 */ 
	private $file_size_per_request_limit 	= 1*1024*1024; //1MB
	private $files_to_process_per_request 	= 100; //block of 512 bytes
	private $compression_level 				= 0; //0-9 , 0 uncompressed
	private $xcloner_split_backup_limit		= 2048; //2048MB
	
	private $archive_name;
	
	public function __construct($hash = "", $archive_name = "")
	{
		$this->filesystem 		= new Xcloner_File_System($hash);
		$this->logger 			= new XCloner_Logger('xcloner_archive', $hash);
		$this->xcloner_settings = new Xcloner_Settings($hash);
		
		if($value = $this->xcloner_settings->get_xcloner_option('xcloner_size_limit_per_request'))
			$this->file_size_per_request_limit = $value*1024*1024; //MB
			
		if($value = $this->xcloner_settings->get_xcloner_option('xcloner_files_to_process_per_request'))
			$this->files_to_process_per_request = $value;
		
		if($value = get_option('xcloner_backup_compression_level'))
			$this->compression_level = $value;
		
		if($value = get_option('xcloner_split_backup_limit'))
			$this->xcloner_split_backup_limit = $value;
		
		$this->xcloner_split_backup_limit = $this->xcloner_split_backup_limit * 1024*1024; //transform to bytes
			
		if(isset($archive_name))
			$this->set_archive_name($archive_name);
	}
	
	public function rename_archive($old_name, $new_name)
	{
		$this->logger->info(sprintf("Renaming backup archive %s to %s", $old_name, $new_name));
		$storage_filesystem = $this->filesystem->get_storage_filesystem();
		$storage_filesystem->rename($old_name, $new_name);
	}
	
	public function set_archive_name($name = "", $part = 0)
	{
		$this->archive_name = $this->filesystem->process_backup_name($name);
		
		if(isset($part) and $part)
		{
			$new_name =  preg_replace('/-part(\d*)/', "-part".$part, $this->archive_name);
			if(!stristr($new_name, "-part"))
				$new_name = $this->archive_name . "-part".$part;
			
			$this->archive_name = $new_name;	
		}

		return $this;
	}
	
	public function get_archive_name()
	{
		return $this->archive_name;
	}
	
	public function get_archive_name_multipart()
	{
		$new_name =  preg_replace('/-part(\d*)/', "", $this->archive_name);
		return $new_name."-multipart.csv";
	}
	
	public function get_archive_name_with_extension()
	{
		return $this->archive_name.$this->xcloner_settings->get_backup_extension_name();
	}
	
	public function start_incremental_backup($backup_params, $extra_params, $init)
	{
		if(!isset($extra_params['backup_part']))
			$extra_params['backup_part'] = 0;
		
		$return['extra']['backup_part'] = $extra_params['backup_part'];
					
		if(isset( $extra_params['backup_archive_name']))
			$this->set_archive_name($extra_params['backup_archive_name'], $return['extra']['backup_part']);
		else
			$this->set_archive_name($backup_params['backup_name']);
			
		if(!$this->get_archive_name())
			$this->set_archive_name();
		
		$this->backup_archive = new Tar();
		$this->backup_archive->setCompression($this->compression_level);
		
		$archive_info = $this->filesystem->get_storage_path_file_info($this->get_archive_name_with_extension());
		
		if($init)
		{
			$this->logger->info(sprintf(__("Initializing the backup archive %s"), $this->get_archive_name()));
		
			$this->backup_archive = new Tar();
			$this->backup_archive->setCompression($this->compression_level);
			$this->backup_archive->create($archive_info->getPath().DS.$archive_info->getFilename());
			
			$return['extra']['backup_init'] = 1;
			
		}else{
			$this->logger->info(sprintf(__("Opening for append the backup archive %s"), $this->get_archive_name()));
			
			$this->backup_archive->openForAppend($archive_info->getPath().DS.$archive_info->getFilename());
			
			$return['extra']['backup_init'] = 0;
			
		}
		
		$return['extra']['backup_archive_name'] = $this->get_archive_name();
		$return['extra']['backup_archive_name_full'] = $this->get_archive_name_with_extension();
		
		if(!isset($extra_params['start_at_line']))
			$extra_params['start_at_line'] = 0;
		
		if(!isset($extra_params['start_at_byte']))
			$extra_params['start_at_byte'] = 0;
		
		if(!$this->filesystem->get_tmp_filesystem()->has($this->filesystem->get_included_files_handler()))
		{
			$this->logger->error(sprintf("Missing the includes file handler %s, aborting...", $this->filesystem->get_included_files_handler()));
			
			$return['finished'] = 1;
			return $return;
		}
			
		$included_files_handler = $this->filesystem->get_included_files_handler(1);	
		
		$file = new SplFileObject($included_files_handler);
		
		$file->seek(PHP_INT_MAX);

		$return['extra']['lines_total'] = ($file->key());
		
		$file->seek($extra_params['start_at_line']);
		
		$this->processed_size_bytes = 0;
		
		$counter = 0;
		
		$start_byte = $extra_params['start_at_byte'];
		
		//$this->files_to_process_per_request = 10;
		//$this->file_size_per_request_limit = 1024*1024;
		$byte_limit = 0;
		
		while(!$file->eof() and $counter<=$this->files_to_process_per_request)
		{
			$line = str_getcsv($file->current());

			$relative_path = $line[0];
			
			$start_filesystem = "start_filesystem";
			
			if(isset($line[4])){
				$start_filesystem = $line[4];
			}
			
			$adapter = $this->filesystem->get_adapter($start_filesystem);
			
			if(!$this->filesystem->get_filesystem($start_filesystem)->has($relative_path))
			{
				$extra_params['start_at_line']++;
				$file->next();
				continue;
			}
			
			$file_info = $this->filesystem->get_filesystem($start_filesystem)->getMetadata($relative_path);
			
			//echo "Processing ".$file_info['path']."(".$file_info['size'].")";
			
			if(!isset($file_info['size']))
				$file_info['size'] = 0;
			
			if($start_filesystem == "tmp_filesystem")	
				$file_info['archive_prefix_path'] = "xcloner".$this->xcloner_settings->get_hash();
			
			$byte_limit = (int)$this->file_size_per_request_limit/512;
			
			$append = 0;
			
			if($file_info['size'] > $byte_limit*512 or $start_byte)
				$append = 1;
						
			if(!isset($return['extra']['backup_size']))
				$return['extra']['backup_size'] =0;
			
			$return['extra']['backup_size'] = $archive_info->getSize();
			
			$estimated_new_size = $return['extra']['backup_size'] + $file_info['size'];
			
			//we create a new backup part if we reach the Split Achive Limit
			if($this->xcloner_split_backup_limit and ($estimated_new_size > $this->xcloner_split_backup_limit) and (!$start_byte))
			{
				$this->logger->info(sprintf("Backup size limit %s bytes reached, file add estimate %s, attempt to create a new archive ",$this->xcloner_split_backup_limit, $estimated_new_size));
				list($archive_info, $return['extra']['backup_part']) = $this->create_new_backup_part($return['extra']['backup_part']);
				
				if($file_info['size'] > $this->xcloner_split_backup_limit)
				{
					$this->logger->info(sprintf("Excluding %s file as it's size(%s) is bigger than the backup split limit of %s and it won't fit a single backup file",$file_info['path'], $file_info['size'], $this->xcloner_split_backup_limit));
					$extra_params['start_at_line']++;
				}
				
				$return['extra']['start_at_line'] = $extra_params['start_at_line'];
				$return['extra']['start_at_byte'] = 0;
				
				$return['finished'] = 0;
				
				return $return;
			}
			
			list($bytes_wrote, $last_position) = $this->add_file_to_archive( $file_info, $start_byte, $byte_limit, $append, $adapter);
			$this->processed_size_bytes += $bytes_wrote;
			
			//echo" - processed ".$this->processed_size_bytes." bytes ".$this->file_size_per_request_limit." last_position:".$last_position." \n";
			$return['extra']['processed_file'] = $file_info['path'];
			$return['extra']['processed_file_size'] = $file_info['size'];
			$return['extra']['backup_size'] = $archive_info->getSize();
			
			if($last_position>0){	
				$start_byte = $last_position;
			}
			else{	
				
				//remove the tmp files
				/*if($start_filesystem == "tmp_filesystem"){
					$this->logger->info(sprintf("Deleting temporary file %s from the system", $file_info['path']));
					$this->filesystem->get_filesystem($start_filesystem)->delete($file_info['path']);
				}*/
					
				$extra_params['start_at_line']++;
				$file->next();
				$start_byte = 0;
				$counter++;
				//echo "Processing next file ".$extra_params['start_at_line']."\n";
			}
			
			if($this->processed_size_bytes >= $this->file_size_per_request_limit)
			{
				$return['finished'] = 0;
				$return['extra']['start_at_line'] = $extra_params['start_at_line'];
				$return['extra']['start_at_byte'] = $last_position;
				$this->logger->info(sprintf("Reached the maximum %s request limit, returning response", $this->file_size_per_request_limit));
				return $return;
			}
		}
		
		if(!$file->eof())
		{
			$return['finished'] = 0;
			$return['extra']['start_at_line'] = $extra_params['start_at_line'];
			$return['extra']['start_at_byte'] = $last_position;
			$this->logger->info(sprintf("We have reached the maximum files to process per request limit of %s, returning response", $this->files_to_process_per_request));

			return $return;
		}
		
		//close the backup archive by adding 2*512 blocks of zero bytes
		$this->logger->info(sprintf("Closing the backup archive %s with 2*512 zero bytes blocks.", $this->get_archive_name_with_extension()));
		$this->backup_archive->close();
		
		
		
		if($return['extra']['backup_part'])
			$this->write_multipart_file($this->get_archive_name_with_extension());
		
		$return['extra']['start_at_line'] = $extra_params['start_at_line']-1;
		$return['extra']['processed_file'] = $file_info['path'];
		$return['extra']['processed_file_size'] = $file_info['size'];
		$return['extra']['backup_size'] = $archive_info->getSize();
		$return['finished'] = 1;
		return $return;
	}
	
	private function write_multipart_file($path)
	{
		$path = $this->get_archive_name_with_extension();
		
		$file = $this->filesystem->get_filesystem("storage_filesystem_append")->getMetadata($path);
		//print_r($file_info);
		$line = '"'.$file['path'].'","'.$file['timestamp'].'","'.$file['size'].'"'.PHP_EOL;

		
		$this->filesystem->get_filesystem("storage_filesystem_append")
						->write($this->get_archive_name_multipart(), $line);
	}
	
	private function create_new_backup_part($part = 0)
	{
		//close the backup archive by adding 2*512 blocks of zero bytes
		$this->logger->info(sprintf("Closing the backup archive %s with 2*512 zero bytes blocks.", $this->get_archive_name_with_extension()));
		$this->backup_archive->close();		
		
		if(!$part)
		{
			$old_name = $this->get_archive_name_with_extension();
			$this->set_archive_name($this->get_archive_name(), ++$part);
			$this->rename_archive($old_name, $this->get_archive_name_with_extension());
			
			$this->write_multipart_file($this->get_archive_name_with_extension());
			
		}else
		{
			$this->logger->info(sprintf("Creating new multipart info file %s",$this->get_archive_name_with_extension()));
			$this->write_multipart_file($this->get_archive_name_with_extension());
		}
				
		$this->set_archive_name($this->get_archive_name(), ++$part);		
		
		$this->logger->info(sprintf("Creating new backup archive part %s", $this->get_archive_name_with_extension()));

		$this->backup_archive = new Tar();
		$this->backup_archive->setCompression($this->compression_level);
		$archive_info = $this->filesystem->get_storage_path_file_info($this->get_archive_name_with_extension());
		$this->backup_archive->create($archive_info->getPath().DS.$archive_info->getFilename());
		
		return array($archive_info, $part);
		
	}
	
	public function add_file_to_archive($file_info, $start_at_byte, $byte_limit = 0, $append, $start_adapter)
	{
		if(!$file_info['path'])
			return;
		
		if(isset($file_info['archive_prefix_path']))	
			$file_info['target_path'] = $file_info['archive_prefix_path']."/".$file_info['path'];
		else
			$file_info['target_path'] = $file_info['path'];
			
		$last_position = $start_at_byte;
		
		//$start_adapter = $this->filesystem->get_start_adapter();

		if(!$append){
			$bytes_wrote = $file_info['size'];
			$this->logger->info(sprintf("Adding %s bytes of file %s to archive %s ", $bytes_wrote, $file_info['target_path'], $this->get_archive_name_with_extension()));
			$this->backup_archive->addFile($start_adapter->applyPathPrefix($file_info['path']), $file_info['target_path']);
		}
		else{	
			$last_position = $this->backup_archive->appendFileData($start_adapter->applyPathPrefix($file_info['path']), $file_info['target_path'], $start_at_byte, $byte_limit);
			if($last_position == -1)
				$bytes_wrote = $file_info['size'] - $start_at_byte;
			else
				$bytes_wrote = $last_position - $start_at_byte;
			
			$this->logger->info(sprintf("Appending %s bytes, starting position %s, of file %s to archive %s ", $bytes_wrote, $start_at_byte, $file_info['target_path'], $this->get_archive_name()));
			
		}
		
		return array($bytes_wrote, $last_position);
	}
	    
    /**
     * Open a TAR archive and put the file cursor at the end for data appending
     *
     * If $file is empty, the tar file will be created in memory
     *
     * @param string $file
     * @throws ArchiveIOException
     */
    /*
    public function openForAppend($file = '')
    {
        $this->file   = $file;
        $this->memory = '';
        $this->fh     = 0;

        if ($this->file) {
            // determine compression
            if ($this->comptype == Archive::COMPRESS_AUTO) {
                $this->setCompression($this->complevel, $this->filetype($file));
            }

            if ($this->comptype === Archive::COMPRESS_GZIP) {
                $this->fh = @gzopen($this->file, 'ab'.$this->complevel);
            } elseif ($this->comptype === Archive::COMPRESS_BZIP) {
                $this->fh = @bzopen($this->file, 'a');
            } else {
                $this->fh = @fopen($this->file, 'ab');
            }

            if (!$this->fh) {
                throw new ArchiveIOException('Could not open file for writing: '.$this->file);
            }
        }
        $this->backup_archive->writeaccess = true;
        $this->backup_archive->closed      = false;
    }
    */
    
     /**
     * Append data to a file to the current TAR archive using an existing file in the filesystem
     *
     * @param string          	$file     path to the original file
     * @param int          		$start    starting reading position in file
     * @param int          		$end      end position in reading multiple with 512
     * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data, empty to take from original
     * @throws ArchiveIOException
     */
    /*
     * public function appendFileData($file, $fileinfo = '', $start = 0, $limit = 0)
    {
		$end = $start+($limit*512);
		
		//check to see if we are at the begining of writing the file
		if(!$start)
		{
	        if (is_string($fileinfo)) {
				$fileinfo = FileInfo::fromPath($file, $fileinfo);
	        }
		}
		
        if ($this->closed) {
            throw new ArchiveIOException('Archive has been closed, files can no longer be added');
        }

        $fp = fopen($file, 'rb');
        
        fseek($fp, $start);
        
        if (!$fp) {
            throw new ArchiveIOException('Could not open file for reading: '.$file);
        }

        // create file header
		if(!$start)
			$this->backup_archive->writeFileHeader($fileinfo);
		
		$bytes = 0;
        // write data
        while ($end >=ftell($fp) and !feof($fp) ) {
            $data = fread($fp, 512);
            if ($data === false) {
                break;
            }
            if ($data === '') {
                break;
            }
            $packed = pack("a512", $data);
            $bytes += $this->backup_archive->writebytes($packed);
        }
        
        
        
        //if we are not at the end of file, we return the current position for incremental writing
        if(!feof($fp))
			$last_position = ftell($fp);
		else
			$last_position = -1;
	
        fclose($fp);
        
        return $last_position;
    }*/
    
    /**
     * Adds a file to a TAR archive by appending it's data
     *
     * @param string $archive			name of the archive file
     * @param string $file				name of the file to read data from				
     * @param string $start				start position from where to start reading data
     * @throws ArchiveIOException
     */
	public function addFileToArchive($archive, $file, $start = 0)
	{
		$this->openForAppend($archive);
		return $start = $this->appendFileData($file, $start, $this->file_size_per_request_limit);
	}
	

}