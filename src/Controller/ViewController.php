<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\NeoService;

use Symfony\Component\Validator\Constraints\DateTime;


class ViewController extends AbstractController
{
// List old Item & fetch current item

	#[Route('/frameN/{frameName}/{domain}/{metaType}/{instanceID}', name: 'frameN')]
	public function loadframeByName($frameName, $domain="", $metaType="", $instanceID="")
	{
	   $neolib = new NeoService();
	   $frame = $neolib->getFrameByName($frameName);
	   return $this->getFrame($frame,$domain,$metaType,$instanceID);
	}		 
	
	#[Route('/frame/{frameID}/{domain}/{metaType}/{instanceID}', name: 'frame')]
	public function loadframe($frameID, $domain, $metaType, $instanceID)
	{
	   $frame = $neolib->getFrame($frameID);
	   return $this->getFrame($frame,$domain,$metaType,$instanceID);
	}	 
	
	// gets the actual Frame (originated from ID or Name)
	public function getFrame($frame,$domain,$metaType,$instanceID)
	{
	   $ViewID = $frame['view']['in_id'];
	   $renderedView = $this->HTMLView($domain, $metaType, $ViewID, $instanceID, -1, "");
	   $paramArray = array(
		   'subtit' => $frame['frame']['name'],
		   'domain' => $domain,
		   'metaType' => $metaType,
		   'instanceID' => $instanceID,
		   'ViewContent' => $renderedView,			
		   'ViewID' => $ViewID,
		   );
	   return $this->render('view/frames/'.$frame['frame']['templateName'], $paramArray);	
	} 	 

	// Get the data of one instance - returns a JSON response for frontend
	#[Route('/getviewdata/{queryID}/{lookupID}', name: 'dynamic_getviewdata')]	
    public function getviewData(Request $request, $queryID, $lookupID)
    {
		$neolib = new NeoService();			
        return new JsonResponse($neolib->getQueryData($queryID, $lookupID));
    }	
	
	// Get the render of one view - JSONifies the HTML render for frontend
	 #[Route('/j_htmlview/{domain}/{metaType}/{viewID}/{instanceID}/{page}', name: 'data_browser_jhtmllist', requirements: ["page" => "\d+"])]
    public function getHTMLViewData(Request $request, $domain, $metaType, $viewID, $instanceID, $page = -1)
    {
		$searchString = $request->query->get('search');
		return new JsonResponse(
					array("code" => 200, 
						  "response" => $this->HTMLView($domain, $metaType, $viewID, $instanceID, $page, $searchString)));
	}
	
	// returns the actual HTML rendered response (allowing customization)
	public function HTMLView($domain, $metaType, $viewID, $instanceID, $page, $searchString)
	{
		$neolib = new NeoService();	
		$theData = $neolib->getView( $viewID, false );
		
		if ($page!=-1)
		{
			// get record count for paginated view
			$countq='MATCH (n:{metaType}) where n.domain={domain} return count(n)';
			$countq=str_replace('{domain}','\''.$domain.'\'',$countq);		
			$countq=str_replace('{metaType}',$metaType, $countq);		
			$result=$neolib->QueryToArr($countq,[]);
			$metaTypeRecCount = $result[0]['count(n)'];
		} else{
			$metaTypeRecCount = -1;
		}
		if (array_key_exists('RecordsPerPage', $theData['view']))
		{
			$maxRecords = $theData['view']['RecordsPerPage'];
		} else 
		{
			$maxRecords = 100;
		}
		
		$atRecord = max(($page * $maxRecords),0);
		// do we have a searchString?
		$query = ($searchString == "") ? $query = $theData['view']['query'] : $theData['view']['searchquery'];

		$query=str_replace('{SourceNodeID}',$instanceID,$query);
		$query=str_replace('{instanceID}',$instanceID,$query);
		$query=str_replace('{domain}',$domain,$query);		
		$query=str_replace('{metaType}',$metaType, $query);
		$query=str_replace('$metaType',$metaType, $query);
		$query=str_replace('{maxRecords}',$maxRecords,$query);
		$query=str_replace('{atRecord}',$atRecord,$query);	
		$query=str_replace('{searchstring}',$searchString,$query);				

		$theQueryData=$neolib->QueryToArr($query,[]);

		if ($theData['view']['ViewKind'] == 'Node' || $theData['view']['ViewKind'] == 'FullNode')
		{
			$theResult = $theQueryData;
			$theResult[0]['entity']["in_metaType"]=$metaType;
		} else
		// for table structures (keys [0] -> Round, values [0] -> 1, etc...)
		if ($theData['view']['ViewKind'] == 'Table')
		{
			$theResult = [];
			$keys = [];
			$count = 0;
			
			foreach ($theQueryData as $record) {
				//dump($record);
				$res = [];
				for ($i = 0; $i < count(array_keys($record)); $i++) 
				{	$thiskey = array_keys($record)[$i];
					$res[] = array_values($record)[$i];
					if ($count == 0 && !in_array($thiskey,$keys)) $keys[] = array_keys($record)[$i];
				}
				$theResult[$count] = $res;
				$count++;
			}
			$theResult['keys']=$keys;
		}

		if ($theData['view']['description'] != '')
		{
			$arr = explode('|',$theData['view']['description']);
			$res = [];
			foreach ($arr as $rec){
				$temp = explode(',',$rec);
				$name = array_shift($temp);
				$res[$name] = $temp;
			}
			$theData['view']['behavior']=$res;
		}

		//dump($theData);

		$resultArray = array(
			'instanceID' => $instanceID,
			'domain' => $domain, // needed for "add" button, amongst others
			'metaType' => $metaType,
			'theResult' => $theResult,
			'recCount' => $metaTypeRecCount,
			'pageNum' => $page,
			'searchString' => $searchString,			
			'theView' => $theData['view']);

		return $this->render('view/templates/'.$theData['view']['templateName'], $resultArray)->getContent();
    }


	// Get the queries of one metaType - returns a JSON response
	#[Route('/getqueries/{domain}/{metaType}', name: 'dynamic_getqueries')]	
    public function getQueries(Request $request, $domain, $metaType)
    {
		$neolib = new NeoService();			
	
		$queries = $neolib->get_json_queries($domain, $metaType);
	
        return new JsonResponse($queries);
    }	
}
