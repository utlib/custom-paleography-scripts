<?php

define("FORCE_CREATE", true);


/* We must have all the Tupue php files */
foreach (glob( dirname(getcwd()) . "/lib/tuque/*.php") as $filename) {
  require_once $filename;
}

class MyFedoraObject {

    protected $url;
    protected $user;
    protected $pass;
    protected $repository;
    
    function __construct() {
        $this->url = getenv('fedoraURL');
        $this->user = getenv('fedoraAdmin');
        $this->pass = getenv('fedoraPassword');

        $this->init_connection();
    }
    
    private function init_connection() {
        $connection = new RepositoryConnection($this->url, $this->user, $this->pass);
        $connection->reuseConnection = TRUE;
        $this->repository = new FedoraRepository(
           new FedoraApi($connection),
           new SimpleCache());
    }
    
    public function get_url() {
        return $this->url;
    }
    
    public function get_object($pid = "") {
        if ($pid != "") {
            try {
                return $this->repository->getObject($pid);
            }catch (Exception $e) {
                return "Error while accessing object: $e\n";
            }
        }
    }

    public function get_children($pid = "") {
        if ($pid != "") {
            try {
                $ri = $this->repository->ri;
                return $ri->sparqlQuery("SELECT *
                    FROM <#ri>
                    WHERE {
                     ?pid <fedora-rels-ext:isMemberOfCollection> <info:fedora/$pid> .
                    }");
            }catch(Exception $e) {
                return "Error while accessing children: $e\n";
            }
        }
    }
    
    public function get_pages($pid = "") {
        if ($pid != "") {
            try {
                $ri = $this->repository->ri;
                return $ri->sparqlQuery("SELECT *
                    FROM <#ri>
                    WHERE {
                     ?pid <fedora-rels-ext:isMemberOf> <info:fedora/$pid> .
                    }");
            }catch(Exception $e) {
                return "Error while accessing children: $e\n";
            }
        }
    }


    public function add_datastream($object, $dsid, $content) {
        if ($object) {
            $datastream = isset($object[$dsid]) ? $object[$dsid] : $object->constructDatastream($dsid);
            $datastream->label = 'SharedCanvas Manifest';
            $datastream->mimeType = 'application/json';
            $datastream->setContentFromString($content);
            
            // There's no harm in doing this if the datastream is already ingested or if the object is only constructed.
            $object->ingestDatastream($datastream);
            // If the object IS only constructed, ingesting it here also ingests the datastream.
            //$this->repository->ingestObject($object);
        }
    }

}

$base_url = getenv('islandora_baseurl');

// initialize object
$fo = new MyFedoraObject();

echo $fo->get_url() . "\n";

$manuscripts = $fo->get_children(getenv('islandora_collection'));

foreach ($manuscripts as $manuscript) {
    $pid = $manuscript['pid']['value'];
    echo "Processing $pid\n";
    
    $object = $fo->get_object($pid);
    
    $pages = $fo->get_pages($pid);
    foreach($pages as $page) {
        $page_pid = $page['pid']['value'];
        
        $page_object = $fo->get_object($page_pid);

        // only create new datastream if it doesn't already exist (or if the force flag is set)
        if (!isset($page_object['JPGHIRES']) || FORCE_CREATE) {
            echo "Processing Page: $page_pid\n";
            // write TIFF to disk
            $page_object['OBJ']->getContent("scratch/".$page_pid.".OBJ");
            // convert TIFF to JPG
            exec("convert -strip -resize x2000 scratch/".$page_pid.".OBJ scratch/".$page_pid.".JPG 2>/dev/null",$retArr,$retVal);
            
            if ($retVal === 0) {
                // create new datastream
                $ds = $page_object->constructDatastream('JPGHIRES');
                $ds->label = 'High Resolution JPEG';
                $ds->mimetype = 'image/jpeg';
                $ds->setContentFromFile("scratch/".$page_pid.".JPG");
                $page_object->ingestDatastream($ds);
 
                echo "High resolution JPG created!\n";
 
                // clean up scratch directory
                exec("rm -f scratch/".$page_pid."*");
            }else {
                echo "There was a problem coverting the TIFF to JPG!\n";
            }
        }
       
        //break;
    }
    
    //break;
}

