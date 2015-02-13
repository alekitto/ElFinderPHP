<?php

namespace FM\ElFinderPHP\Driver;

use Aws\S3\S3Client;
use FM\ElFinderPHP\ElFinder;

/**
 * @file
 *
 * elFinder driver for Amazon S3 (SOAP) filesystem.
 *
 * @author Dmitry (dio) Levashov,
 * @author Alexey Sukhotin
 * */
class ElFinderVolumeS3 extends ElFinderVolumeDriver {

    protected $driverId = 's3s';

    /**
     * @var S3Client
     */
    protected $s3;

    public function __construct() {
        $opts = array(
            'accesskey' => '',
            'secretkey' => '',
            'bucket'    => '',
            'tmpPath'   => '',
            'endpoint'  => ''
        );
        $this->options = array_merge($this->options, $opts);
        $this->options['mimeDetect'] = 'internal';

    }

    protected function init() {
        if (!$this->options['accesskey']
            ||  !$this->options['secretkey']
            ||  !$this->options['bucket']) {
            return $this->setError('Required options undefined.');
        }

        $options = array(
            'key' => $this->options['accesskey'],
            'secret' => $this->options['secretkey']
        );

        if (!empty($this->options['endpoint'])) {
            $options['base_url'] = $this->options['endpoint'];
        }

        if (!empty($this->options['region'])) {
            $options['region'] = $this->options['region'];
        }

        $this->s3 = S3Client::factory($options);
        $this->root = $this->options['path'];

        $this->rootName = 's3';

        return true;
    }

    protected function configure() {
        parent::configure();
        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || @mkdir($this->options['tmpPath'])) && is_writable($this->options['tmpPath'])) {
                $this->tmpPath = $this->options['tmpPath'];
            }
        }
        $this->mimeDetect = 'internal';
    }

    /**
     * Return parent directory path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path) {

        $newpath =  preg_replace("/\/$/", "", $path);
        $dn = substr($path, 0, strrpos($newpath, '/')) ;

        if (substr($dn, 0, 1) != '/') {
            $dn = "/$dn";
        }

        return $dn;
    }

    /**
     * Return file name
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path) {
        return basename($path);
    }



    /**
     * Join dir name and file name and return full path.
     * Some drivers (db) use int as path - so we give to concat path to driver itself
     *
     * @param  string  $dir   dir path
     * @param  string  $name  file name
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name) {
        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string  $path  path
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path) {
        $tmp =  preg_replace("/^\//", "", $path);
        $tmp =  preg_replace("/\/\//", "/", $tmp);
        $tmp =  preg_replace("/\/$/", "", $tmp);
        return $tmp;
    }

    /**
     * Return file path related to root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path) {
        $newpath = $this->_normpath($path);
        $normroot = $this->_normpath($this->root);

        if ($newpath == $normroot) {
            return '';
        }

        $ret = substr($newpath, strlen($this->root));

        return $ret;
    }

    /**
     * Convert path related to root dir into real path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path) {
        return $path == $this->separator ? $this->root : ($this->root.$this->separator.$path);
    }

    /**
     * Return fake path started from root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path) {
        return $this->rootName.($path == $this->root ? '' : $this->separator.$this->_relpath($path));
    }

    /**
     * Return true if $path is children of $parent
     *
     * @param  string  $path    path to check
     * @param  string  $parent  parent path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent) {
        return $path == $parent || strpos($path, $parent.'/') === 0;
    }

    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     *
     * If file does not exists - returns empty array or false.
     *
     * @param  string  $path    file path
     * @return array|false
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _stat($path) {

        $stat = array(
            'size' => 0,
            'ts' => time(),
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false,
            'mime' => 'directory',
        );


        if ($this->root == $path) {
            return $stat;
        }


        $np = $this->_normpath($path);

        try {
            $obj = $this->s3->getObject(array('Bucket' => $this->options['bucket'], 'Key' => $np , 'GetMetadata' => true, 'InlineData' => false, 'GetData' => false));
        } catch (\Exception $e) {

        }

        if (!isset($obj)) {
            $np .= '/';
            try {
                $obj = $this->s3->getObject(array('Bucket' => $this->options['bucket'], 'Key' => $np , 'GetMetadata' => true, 'InlineData' => false, 'GetData' => false));
            } catch (\Exception $e) {

            }
        }

        if (!isset($obj)) {
            return array();
        }

        $mime = '';
        $mime = $obj->get('ContentType');
        $stat['size'] = $obj->get('ContentLength');

        if (!empty($mime)) {
            $stat['mime'] = ($mime == 'binary/octet-stream') ? 'directory' : $mime;
        }

        if (isset($obj->LastModified)) {
            $stat['ts'] = strtotime($obj->LastModified);
        }

        return $stat;
    }



    /***************** file stat ********************/


    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Alexey Sukhotin
     **/
    protected function _subdirs($path) {
        $stat = $this->_stat($path);

        if ($stat['mime'] == 'directory') {
            $files = $this->_scandir($path);
            foreach ($files as $file) {
                $fstat = $this->_stat($file);
                if ($fstat['mime'] == 'directory') {
                    return true;
                }
            }

        }

        return false;
    }

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string  $path  file path
     * @param  string  $mime  file mime type
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dimensions($path, $mime) {
        return false;
    }

    /******************** file/dir content *********************/

    /**
     * Return files list in directory
     *
     * @param  string  $path  dir path
     * @return array
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _scandir($path) {
        $s3path = $this->_normpath($path) . '/';
        $files = $this->s3->listObjects(array('Bucket' => $this->options['bucket'], 'delimiter' => '/', 'Prefix' => $s3path))->get("Contents");
        $finalfiles = array();

        foreach ($files as $file) {
            if (preg_match("|^" . $s3path . "[^/]*/?$|", $file["Key"])) {
                $fname = preg_replace("/\/$/", "", $file["Key"]);
                $fname = $file["Key"];

                if ($fname != preg_replace("/\/$/", "", $s3path)) {

                }

                $finalfiles[] = $fname;
            }
        }

        sort($finalfiles);
        return $finalfiles;
    }

    /**
     * Return temporary file path for required file
     *
     * @param  string  $path   file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function tmpname($path) {
        return $this->tmpPath.DIRECTORY_SEPARATOR.md5($path);
    }

    /**
     * Open file and return file pointer
     *
     * @param  string  $path  file path
     * @param  bool    $write open file for writing
     * @return resource|false
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _fopen($path, $mode="rb") {

        $tn = $this->tmpname($path);

        $fp = $this->tmbPath
            ? @fopen($tn, 'w+')
            : @tmpfile();


        if ($fp) {

            try {
                $obj = $this->s3->getObject(array('Bucket' => $this->options['bucket'], 'Key' => $this->_normpath($path) , 'GetMetadata' => true, 'InlineData' => true, 'GetData' => true));
            }	catch (Exception $e) {

            }

            fwrite($fp, $obj->get("Body"));
            rewind($fp);
            return $fp;
        }

        return false;
    }

    /**
     * Close opened file
     *
     * @param  resource  $fp    file pointer
     * @param  string    $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _fclose($fp, $path='') {
        @fclose($fp);
        if ($path) {
            @unlink($this->tmpname($path));
        }
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new directory name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _mkdir($path, $name) {

        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name/";

        try {
            $obj = $this->s3->putObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey , 'ContentLength' => 0, 'Body' => ''));
        } catch (Exception $e) {

        }

        if (isset($obj)) {
            return "$path/$name";
        }

        return false;
    }

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new file name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _mkfile($path, $name) {
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name";

        try {
            $obj = $this->s3->putObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey , 'ContentLength' => 0, 'Data' => '', 'Metadata' => array(array('Name' => 'Content-Type', 'Value' => 'text/plain'))));
        } catch (Exception $e) {

        }

        if (isset($obj)) {
            return "$path/$name";
        }

        return false;

    }

    /**
     * Create symlink
     *
     * @param  string  $source     file to link to
     * @param  string  $targetDir  folder to create link in
     * @param  string  $name       symlink name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _symlink($source, $targetDir, $name) {
        return false;
    }

    /**
     * Copy file into another file (only inside one volume)
     *
     * @param  string  $source  source file path
     * @param  string  $target  target dir path
     * @param  string  $name    file name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name) {
        return false;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string  $source  source file path
     * @param  string  $target  target dir path
     * @param  string  $name    file name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _move($source, $targetDir, $name) {
        return false;
    }

    /**
     * Remove file
     *
     * @param  string  $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path) {

        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);

        try {
            $obj = $this->s3->deleteObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey));
        } catch (Exception $e) {

        }

        /*$fp = fopen('/tmp/eltest.txt','a+');

        fwrite($fp, 'key='.$newkey);*/

        if (is_object($obj)) {
            return true;
        }


        //fclose($fp);

        return false;
    }

    /**
     * Remove dir
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path) {
        return $this->_unlink($path . '/');
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource  $fp   file pointer
     * @param  string    $dir  target dir path
     * @param  string    $name file name
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $dir, $name, $mime, $stat) {
        $newkey = $this->_normpath($dir);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name";

        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }

        try {
            $obj = $this->s3->putObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey , 'ContentLength' => strlen($content), 'Body' => $content, 'ContentType' => $mime));
        } catch (Exception $e) {

        }

        if (isset($obj)) {
            return "$dir/$name";
        }

        return false;
    }

    /**
     * Get file contents
     *
     * @param  string  $path  file path
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path) {
        return false;
    }

    /**
     * Write a string to a file
     *
     * @param  string  $path     file path
     * @param  string  $content  new file content
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content) {
        return false;
    }

    /**
     * Extract files from archive
     *
     * @param  string  $path file path
     * @param  array   $arc  archiver options
     * @return bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _extract($path, $arc) {
        return false;
    }

    /**
     * Create archive and return its path
     *
     * @param  string  $dir    target dir
     * @param  array   $files  files names list
     * @param  string  $name   archive name
     * @param  array   $arc    archiver options
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _archive($dir, $files, $name, $arc) {
        return false;
    }

    /**
     * Detect available archivers
     *
     * @return void
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _checkArchivers() {

    }

}
