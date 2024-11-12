<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\NeoService;

class DataBrowserController extends AbstractController
{
	
	// Initial screen of data browser (shows domains)
	#[Route('/databrowser', name: 'data_browser')]
    public function index()
    {
		$neolib = new NeoService();			
		$data = $neolib->get_domains('TopNavTree');
        return $this->render('data_browser/index.html.twig',array(
			'domains' => $data,
			'subtit' => 'Data browser') );
    }
	
	// --------------------------------------------------------------------------------------[ JSON Repsonses from here on down ]
	
	// List all items of a domain (Dynamic) - returns a JSON response
	#[Route('/j_list/{domain}/{metaType}/{page}/{search}', name: 'data_browser_jlist', requirements: ["search" => "\d+"])]
    public function getListOfInstances(Request $request, $domain, $metaType, $page, $search = "")
    {
		$ipp = 20;
        //$query = 'MATCH (m:'.$metaType.') WHERE m.domain=$domain RETURN m ORDER BY m.name';
		if ($search=='')
		{
			$query = 'MATCH (m:'.$metaType.') WHERE m.domain=$domain WITH count(m) as c MATCH (m:'.$metaType.') WHERE m.domain=$domain WITH m,c ORDER BY m.name SKIP $skip LIMIT $ipp RETURN c as count,COLLECT(m) as recs'; 
		}
		else 
		{
			$query = 'MATCH (m:'.$metaType.') WHERE m.domain=$domain AND lower(m.name) STARTS WITH $search WITH count(m) as c MATCH (m:'.$metaType.') WHERE m.domain=$domain AND lower(m.name) STARTS WITH $search WITH m,c ORDER BY m.name LIMIT {ipp} RETURN c as count,COLLECT(m) as recs'; 
		}
		$skip = $page * $ipp;
		$neolib = new NeoService();	
        $result = $neolib->getNeoCl()->run($query, ['domain' => $domain, 'skip' => $skip, 'ipp' => $ipp, 'search' => $search]);
		
		$record = $result->records();
		$count=0;
		$entities=[];
		if (count($record)>0)
		{
			$count = $record[0]->get('count');
			//print_r($record[0]->get('recs')[0]->values());

			foreach ($record[0]->get('recs') as $record) { $entities[] = $record->values();	}
		}
        return new JsonResponse(array('count'=>$count,'recs'=>$entities));
    }	
}
