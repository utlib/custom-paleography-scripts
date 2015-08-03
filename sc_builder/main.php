<?php

/* We must have all the Tupue php files */
foreach (glob( dirname(getcwd()) . "/lib/tuque/*.php") as $filename) {
  require_once $filename;
}

// include the SC and Canvas files as well
require_once "SharedCanvasManifest.inc";
require_once "Canvas.inc";

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

$base_url = getenv('islandora_baseurl')
$dsid = "JPGHIRES";

// initialize object
$fo = new MyFedoraObject();

echo $fo->get_url() . "\n";

$manuscripts = $fo->get_children(getenv('islandora_collection'));

foreach ($manuscripts as $manuscript) {
    $pid = $manuscript['pid']['value'];
    echo "Processing $pid\n";
    
    $object = $fo->get_object($pid);
    
    $manifest = new SharedCanvasManifest("$base_url/islandora/object/$pid/datastream/SC/view", $object->label, "$base_url/islandora/object/$pid/datastream/MODS");
    
    $pages = $fo->get_pages($pid);
    foreach($pages as $page) {
        $page_pid = $page['pid']['value'];
        echo "Processing Page: $page_pid\n";
        
        $page_object = $fo->get_object($page_pid);
        $page_uri = "$base_url/islandora/object/$page_pid";
        
        $page_object_rels = $page_object['JP2']->relationships;
        $width_rel = $page_object_rels->get('http://islandora.ca/ontology/relsext#', 'width');
        $height_rel = $page_object_rels->get('http://islandora.ca/ontology/relsext#', 'height');
        $width = $width_rel[0]['object']['value'];
        $height = $height_rel[0]['object']['value'];

        // this width and height is for the original JP2
        // recalculate width and height based on fixed height of 2000 (which is the case for JPGHIRES
        $height = round($width * 2000/$height);
        $width = 2000;
        
        $mimetype = $page_object[$dsid]->mimetype;
        
        //echo $mimetype . "\n";
        //echo $width . "\n";
        //echo $height . "\n";

        $canvas = new Canvas($page_uri, $page_object->label, $page_uri."/datastream/MODS/view");
        $canvas->setImage($page_uri."/datastream/$dsid/view", $mimetype, $width, $height);
        $manifest->addCanvas($canvas);

    	//print_r($canvas);
        
        //break;
    }
    
    //break;
    $json = $manifest->getJson();
    $fo->add_datastream(&$object, "SC", $json);

}

