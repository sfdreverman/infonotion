<?php

// iwr https://windows.memgraph.com | iex

namespace App\Service;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Bolt\helpers\Auth;

class NeoService 
{
	public $protocol;
	public $neocl;

    function __construct() {
		$this->neocl = self::getNeo4jClient();
    }

	public function getNeoCl()
	{
		return $this->neocl;
	}

	private function getNeo4jClient()
	{
		$conn = new \Bolt\connection\Socket();
		// Create a new Bolt instance and provide connection object.
		$bolt = new \Bolt\Bolt($conn);
		// Set available Bolt versions for Memgraph.
		$bolt->setProtocolVersions(5.2);
		// Build and get protocol version instance which creates connection and executes handshake.
		$protocol = $bolt->build();
				
		// HELLO IS NEEDED TO ESTABLISH THE CONNECTION .... DO NOT REMOVE!!!!
		$response = $protocol->hello()->getResponse();
		//print_r($response);

		// Login to database with credentials.
		$response = $protocol->logon([])->getResponse();
		//print_r($response);
		return $protocol;
	}

	public function QueryToArr($query,$params)
	{
		$result = $this->neocl->run($query, $params)->pull();
		//dump($result);
		return $this->getRecordsAsArray();
	}

	public function getRecordsAsArray()
	{
		$counter = 0;
		$arresult = [];
		$fields = [];
		
		foreach ($this->neocl->getResponses() as $response) {
			//dump($response);
			if ($response->signature->value == 112)
			{
				if (array_key_exists('fields', $response->content))
				{
					$fields = $response->content['fields'];
					//dump($fields);
				}
			}
			
			if ($response->signature->value == 113)
			{
				//dump($counter);
				$fieldcount = 0;
				foreach ($fields as $field)
				{
					if (gettype($response->content[$fieldcount])=='object') {
			 			$arresult[$counter][$field]= $response->content[$fieldcount]->properties;
					} else {
						if (gettype($response->content[$fieldcount])=='array') {
							$innercounter = 0;
							//dump($response->content[$fieldcount]);
							foreach ($response->content[$fieldcount] as $row)
							{
								if (property_exists($row, 'properties'))
								{
									$arresult[$counter][$field][$innercounter]=$row->properties;
								} else {
									$arresult[$counter][$field][$innercounter]=$row;
								}
								$innercounter++;
							}
						}
						else{ 
							$arresult[$counter][$field]= $response->content[$fieldcount];
						}
					}
					$fieldcount++;
				}
				$counter++;
			}
			// $response is instance of \Bolt\protocol\Response.
			// First response is SUCCESS message for RUN message.
			// Second response is RECORD message for PULL message.
			// Third response is SUCCESS message for PULL message.
		}		
		//dump($counter);
		//dump(count($arresult));		
		return $arresult;
	}

	public function postArrayToInSet($arrayResult)
	{
		$res = '';
		$first = '';
		for ($i = 0; $i < count($arrayResult); $i++) 
		{
			if ($arrayResult[$i]!='dummy')
			{
				$res = $res.$first.'"'.$arrayResult[$i].'"';
				if ($first=='') { $first = ',';}
			}
		}		
		return $res;
	}
	
	// returns a list of all MetaTypes, with the corresponding Domain between ()
	public function get_allMetaTypes(){
		//old $q = 'match (n:Domain) unwind n.name as domain MATCH (m) where domain in labels(m) return m.name+\' (\'+n.name+\')\' as name,m.in_id as id ORDER BY m.name,n.name';
		$q = 'match (n:Domain), (m) where n.name in labels(m) return m.name+\' (\'+n.name+\')\' as name,m.in_id as id ORDER BY m.name,n.name';
		return $this->QueryToArr($q, []);	
	}
	
	// retrieve all instanceTypes of a specific label ($label) (for a list)
	// $relout is a boolean, if true it will also retrieve and return all distinct outbound relations + nodes.
	public function get_instances($label, $relout) {
		$q = '';
		if ($relout == TRUE) { $q = 'MATCH (n:'.$label.') OPTIONAL MATCH (n)-[r]->(m) RETURN DISTINCT n,COLLECT(DISTINCT r) as r2,m ORDER BY n.name,n.in_id'; } 
						else { $q = 'MATCH (n:'.$label.') OPTIONAL MATCH (n)<-[r]-(m:Query) RETURN n,count(m) as qcount ORDER BY n.name';	}
		//dump($q);
		$result = $this->QueryToArr($q, []);
		return $result;
	}
	
	// Adds a new node with label $label. This basically creates a new datatype that can be instantiated with the user interface.
	// $data holds all information that was input via the addtype.html.twig form.
	public function add_metatype($label, $data)
	{
		$q = 'MERGE (newtype:'.$label.' {in_id:\''.uniqid($data['typename'],true).'\', name: \''.$data['typename'].'\'}) ';
		// Add all attributes
		if (array_key_exists('attr_name', $data)) {
			$length = count($data['attr_name']);
			for ($i = 0; $i < $length; $i++) { // as of now, every MetaAttr is unique to the $label, so no re-use for attributes (merge the whole pattern)
				$q = $q.'MERGE (newtype)-[:HASATTR]->(:MetaAttr {name:\''.$data['attr_name'][$i].'\', attrtype:\''.$data['attr_type'][$i].'\', domain:\'FunctionalType\', in_id:\''.uniqid($data['attr_name'][$i][0],true).'\' }) ';
			}
		}
		// Add all relations, if present
		if (array_key_exists('rel_name', $data)) {
			$lmr = 0; // unique query id for attributes of relation
			$nattrs = 0; // counter for number of attributes (removes the gaps in the 'relcomp' array)
			$attrs = ''; // container for all attributes (to be added last)
			$length = count($data['rel_name']); // number of relations
			for ($i = 0; $i < $length; $i++) {
				if (is_array($data['rel_name'][$i]))
				{ // relname[$i][0] contains the attrname, reltype[$i][0] contains the attrtype
					$attrs = $attrs.' MERGE (lmr'.$lmr.')-[:HASATTR]->(:MetaAttr {in_id:\''.uniqid($data['rel_name'][$i][0],true).'\', name:\''.$data['rel_name'][$i][0].'\', attrtype:\''.$data['rel_type'][$i][0].'\', domain:\'FunctionalType\' }) ';
					$nattrs++;
				}
				else
				{
					$lmr++;
					$lmarr[$lmr] = uniqid($data['rel_name'][$i],true);
					$q = $q.'WITH newtype MATCH (t) WHERE t.in_id=\''.$data['rel_type'][$i].'\' MERGE (newtype)-[:HASREL]->(lmr'.$lmr.':MetaRel {in_id:\''.$lmarr[$lmr].'\', name:\''.$data['rel_name'][$i].'\', iscomp:\''.$data['rel_comp'][$i-$nattrs].'\', multi:'.$data['rel_multi'][$i-$nattrs].', domain:\'FunctionalType\' })-[:TOTYPE]->(t) ';
				}
			}
			// add all attributes last!
			$q = $q.$attrs;
		}
		if (array_key_exists('loattr_name', $data)) {
			$lmr = 0; // unique query id for attributes of relation
			$length = count($data['loattr_name']); // number of relations
			for ($i = 0; $i < $length; $i++) {
					$lmr++;
					$lmarr[$lmr] = uniqid($data['loattr_name'][$i],true);
					$q = $q.'WITH newtype MATCH (t) WHERE t.in_id=\''.$data['loattr_type'][$i].'\' MERGE (newtype)-[:HASLOATTR]->(lmr'.$lmr.':MetaLookupAttr {in_id:\''.$lmarr[$lmr].'\', name:\''.$data['loattr_name'][$i].'\', domain:\'FunctionalType\' })-[:FROMTYPE]->(t) ';
				}
		}
		
		//print_r($q);
		return $this->QueryToArr($q,[]);
	}
	
	// Removes a MetaType from the meta model. This will also delete all relations, attributes and lookup attributes attached to the metatype.
	// Will return an ERROR if there are incoming relationships. (THIS IS DELIBERATE!)
	public function remove_MetaType($domain, $metaTypeID)
	{
		dump($metaTypeID);
		$query = 'MATCH (n:'.$domain.') WHERE n.in_id=$metaTypeID OPTIONAL MATCH (n)-->(m) DETACH DELETE m DELETE n';
		return $this->QueryToArr($query,["metaTypeID" => $metaTypeID]);
	}
	
	//
	// Routines needed to add a typed instance to the Neo4j persistence. (Add/Edit pages)
	//
	
	// METADATA: Retrieve all attributes MetaAttr of $metaType in domain $domain. Returns name, type. orders by name.
	public function get_typeattr($metaType,$domain)
	{
		$q = 'MATCH (n:'.$domain.')-[:HASATTR]->(a:MetaAttr) WHERE n.name = \''.$metaType.'\' RETURN a.name AS name,a.attrtype AS type,a.defval as defval, a.description as desc ORDER BY a.name';
		return $this->QueryToArr($q,[]);
	}	
	
	// METADATA: Retrieve all attributes MetaAttr of MetaRel $relid. Returns name and type. orders by name.
	public function get_relattr($relid)
	{
		$q = 'MATCH (n:MetaRel)-[:HASATTR]->(a:MetaAttr) WHERE n.in_id = \''.$relid.'\' RETURN a.name AS name,a.attrtype AS type,a.defval as defval ORDER BY a.name';
		return $this->QueryToArr($q,[]);
	}
	
	// METADATA: Retrieve all MetaLookupAttr of $label $typeName (Person). Returns name, fromtype, relid. orders by name.
	public function get_metaLookupAttrs($label, $instData)
	{
		$attrName = 'name';
		if ($label == 'Action') {$attrName = 'in_id';}
		$q = 'MATCH (n:'.$label.')-[:HASLOATTR]->(a:MetaLookupAttr)-[:FROMTYPE]-(t) WHERE n.'.$attrName.'= \''.$instData.'\' RETURN a.name AS name, t.name as fromtype, a.in_id as relid, labels(t)[0] as domain, a.description as desc ORDER BY a.name';
		return $this->QueryToArr($q,[]);
	}	
	
	// METADATA: Retrieve all MetaRel of $label $typeName (Person). Returns name, totype, relid, iscomp. orders by name.
	public function get_metaRels($label, $instData)
	{
		$attrName = 'name';
		if ($label == 'Action') {$attrName = 'in_id';}
		$q = 'MATCH (n:'.$label.')-[:HASREL]->(a:MetaRel)-[:TOTYPE]-(t) WHERE n.'.$attrName.'= \''.$instData.'\' RETURN a.name AS name, t.name as totype, a.in_id as relid, a.iscomp as iscomp, a.multi as multi, labels(t)[0] as domain,a.description as desc ORDER BY a.name';
		return $this->QueryToArr($q,[]);
	}	
	
	// Retrieve all instances of a $label. (Domain or Person) Returns name and id. orders by name.
	public function get_instancenames($domain, $label, $skip)
	{
		$q = 'MATCH (n:'.$label.') where n.domain=$domain RETURN n.name AS name, n.in_id as id, n.domain as domain ORDER BY name SKIP '.$skip;
		return $this->QueryToArr($q, ["domain" => $domain]);
	}
	
	// Compiles retrieved data into a Neo4j query to create/merge a typed instance.
	public function CreateOrMergeInstance($domain, $metaType, $metaData, $resultData, $instanceID,$isMetaThing)
	{
		// if in_id is returned, then it is an edited node!!!
		if ($instanceID=="") { $instanceID = $isMetaThing != [] ? uniqid($isMetaThing['makeMT'],true) : uniqid($metaType,true); }
		
		//dump($resultData);
		// Start of Query 
		// Create or find node
		$q = '';
		// if it is a Meta Attribute, LookupAttribute or Relation, then make a Relation too!		
		if ($isMetaThing != [])
		{
			$toRel = ['MetaAttr' => 'HASATTR', 'MetaLookupAttr' => 'HASLOATTR', 'MetaRel' => 'HASREL'];
			// for meta things do:
			$q = $q.'MATCH (cTo:'.$domain.' {name:\''.$metaType.'\'}) WITH cTo ';
			$q = $q.'MERGE (cTo)-[:'.$toRel[$isMetaThing['makeMT']].']->(nti:'.$isMetaThing['makeMT'].' {in_id:\''.$instanceID.'\'}) ';
			// swap makeMT & for metaType
			$domain = $isMetaThing['makedomain'];
			$metaType = $isMetaThing['makeMT'];
		}
		else {
			// for instances do:
			$q = $q.'MERGE (nti:'.$metaType.' {in_id:\''.$instanceID.'\'}) ';
		}
		// Set standard attributes
		$q = $q.'SET nti.domain="'.$domain.'" ';
		
		// Set custom attributes
		//print_r($metaAttrData);
		foreach($metaData['Attr'] as $field) {
			if (is_array($field)) {
				if ($field['type']=='number')
				{
					$q = $q.'SET nti.'.$field['name'].' = '.$resultData[$field['name']].' ';
				} else 
				if ($field['type']=='bool') {
					$tempres = 'false';
					if ($resultData[$field['name']]=='1') {$tempres = 'true';} 
					$q = $q.'SET nti.'.$field['name'].' = '.$tempres.' ';
				}
				else
				if ($field['type']=='date' || $field['type']=='datetime-local')
				{
					$q = $q.'SET nti.'.$field['name'].' = "'.$resultData[$field['name']]->format('Y-m-d\TH:i:s').'" ';
				} else
				{
					$q = $q.'SET nti.'.$field['name'].' = "'.$resultData[$field['name']].'" ';
				}
			}
		}
		
		// Set Lookup Attributes 
		foreach($metaData['LoAttr'] as $field) {
			//print_r($field);
			$q = $q.'SET nti.'.$field['name'].' = "'.$resultData[$field['name']].'" ';
		}		
		
		//print_r('<br><br>');
		
		// Commit
		$result = '';		
		$result = $this->QueryToArr($q,[]);
		
		// Add all relations (if they exist)
		self::CreateOrMergeRelationData($metaType,$instanceID,$metaData['Rel'],$resultData);

		return $result;
	}
	
		// Add 1 or more relations to an instance
	public function CreateOrMergeRelationData($metaType, $instanceID, $relationData, $resultData)
	{
		//dump($relationData);
		$result = '';
		foreach ($relationData as $item)
		{
			$destIds='';
			//the $relname is the relationType
			$cyrelAdd = '[:'.$item['name'].']';
			$cyrelRem = '[r:'.$item['name'].']';
			if ($item['multi']==1) {$destIds = self::postArrayToInSet($resultData[$item['name']]);} 
							  else {$destIds = '"'.$resultData[$item['name']].'"';}
			
			//Remove old relations...
			$query = 'MATCH (n:'.$metaType.') WHERE n.in_id = $instanceID OPTIONAL MATCH (n)-'.$cyrelRem.'->(o) DELETE r';
			// ... and create the new ones.
			if ($destIds!="") {
				$query = $query.' WITH n MATCH (m) WHERE m.in_id IN ['.$destIds.'] MERGE (n)-'.$cyrelAdd.'->(m) ';
			}
			//dump($query);
			$result = $this->QueryToArr($query, ['instanceID' => $instanceID]);
		}
		return $result;
	}
	


	// Returns everything related to label $metaType (all labels Person) -- New Style!
	public function getInstanceEditData($label, $metaType, $instanceID)
    {		
		if ($metaType<>'') {$metaType=':'.$metaType;}
        //$query = 'MATCH (n'.$metaType.') WHERE n.in_id={instanceID} OPTIONAL MATCH (n)-[r]->(m) RETURN DISTINCT n,COLLECT(r) as r2,m.in_id as m';
		$query = 'MATCH (n'.$metaType.') WHERE n.in_id=$instanceID OPTIONAL MATCH (n)-[r]->(m) RETURN n,TYPE(r) as r2,COLLECT(m.in_id) as m';
        $result = $this->QueryToArr($query, ['instanceID' => $instanceID]);
        $relations = [];
		$first = false;

        foreach ($result as $record) {
			//print_r($record);
			
            if ($first == false) { 
				$entityNode = $record['n']; 
				$first = true;
				$relations['entity'] = $entityNode;
			} // only once
			
			//if (!empty($relationNode)) { $relationData = $relationNode;}
			$relationRel  = $record['r2'];
			if ($relationRel != null) {	$relationNode = $record['m']; } else { $relationNode = []; }
			//dump($relationNode);
			$returnValues = [];
			foreach($relationNode as $key => $value) { $returnValues[] = (string)$value; }
			$relations['relprops'][$relationRel] = $returnValues;
        }
        return $relations;
    }			

	// Delete an instance
	public function delete_instance($metaType, $instanceID)
	{
		$query = 'Match (n:'.$metaType.') WHERE n.in_id = $instanceID DETACH DELETE n';
		$result = $this->QueryToArr($query, ['instanceID' => $instanceID]);
	}
	
	// Get Dynamic Query data for display in dynamic browse functionality
	public function getQuery($instanceID)
	{
		$query = 'MATCH (n:Query) WHERE n.in_id=$instanceID RETURN n.query as q';
		$result = $this->QueryToArr($query, ['instanceID' => $instanceID]);
		return $result[0]['q'];
	}
	
	// Execute a query (for a view)
	public function executeQuery($instanceID, $lookupID)
	{
		$query=$this->getQuery($instanceID);
		$query=str_replace('{SourceNodeID}',$lookupID,$query);
		//dump($query);
		return $this->QueryToArr($query,[]);
	}
	
	// Called by ViewController to get json result for front-end
	// turns obsolete?
	public function getQueryData($queryID, $lookupID)
	{
		$result = $this->executeQuery($queryID, $lookupID);
		$queryData = [];
		//dump($result);
		if (!$result==[])
		{
			$queryData['keys']=array_keys($result[0]);
			$queryData['values']=$result;
		}
		return $queryData;
	}
	
	// turns obsolete?
	public function get_queries($label, $metaType)
	{
		$query = 'MATCH (n:'.$label.')<-[:sourceNodeType]-(m:Query) WHERE n.name=$metaType RETURN m ORDER BY m.grouplabel,m.name';
		$result = $this->QueryToArr($query, ['metaType' => $metaType]);
		return $result;
	}	
	
	public function get_json_queries($label, $metaType)
	{
		$tween = $this->get_queries($label, $metaType);
		$queries = [];
		$counter = 0;
		// Put result in a front-end readable format and discard the actual queries (m.query)!
		foreach ($tween as $record) {
			$queries[$counter]['name'] = $record['m']['name'];
			$queries[$counter]['description'] = $record['m']['description'];
			$queries[$counter]['grouplabel'] = $record['m']['grouplabel'];			
			$queries[$counter]['in_id'] = $record['m']['in_id'];
			$counter =$counter +1;
		}
		return $queries;
	}

	// Get Frame by ID
	public function getFrame($instanceID)
	{
		return $this->getFrameRes('match (f:Frame)-[:startView]->(v:View) WHERE f.in_id=$param RETURN f,v', $instanceID);
	}	
	// Get Frame by Name
	public function getFrameByName($instanceName)
	{
		return $this->getFrameRes('match (f:Frame)-[:startView]->(v:View) WHERE replace(toLower(f.name)," ","")=$param RETURN f,v',$instanceName);
	}	
	
	// Get the actual result (originated from ID or Name)
	public function getFrameRes($query, $param)
	{
		$param=strtolower($param);
		$result = $this->QueryToArr($query, ['param' => $param]);
		$res = [];
		dump($query,$param);
		$res['frame']  = $result[0]['f'];		
		$res['view'] = $result[0]['v'];	
		return $res;
	}	

	// Get Domains (for navtree)
	public function get_domains($navTreeName)
	{
		$query = 'MATCH (n:DomainNavTree)-[:hasDomain]->(m:Domain) WHERE n.name=$navTreeName RETURN m';
		$result = $this->QueryToArr($query,['navTreeName' => $navTreeName]);
		return $result;
	}

	public function getView($instanceID)
	{
		$query = 'match (v:View) WHERE v.in_id = $instanceID RETURN v';
		$result = $this->QueryToArr($query,['instanceID' => $instanceID]);
		$res = [];
		$res['view']  = $result[0]['v'];	
		return $res;
	}

}



