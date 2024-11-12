<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\NeoService;

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

use Symfony\Component\Validator\Constraints\DateTime;


class DataEditorController extends AbstractController
{
	private $metaNames = ['MetaAttr' => 'an Attribute', 'MetaLookupAttr' => 'a Lookup Attribute', 'MetaRel' => 'a Relation'];
	private $neolib = null;
	function __construct() {
		$this->neolib = new NeoService();
    }

	// Delete an instance of a metaType (Person, Company, etc...)
	#[Route('/dataeditor/delete/{domain}/{metaType}/{instanceID}', name: 'instance_delete')]	
	public function deleteInstance($domain,$metaType,$instanceID)
	{
		$this->neolib->delete_instance($metaType, $instanceID);
		return $this->redirectToRoute('frameN', array('frameName' => 'databrowser', 'domain' => $domain, 'metaType'=>$metaType));		
	}	
	
    // add a structure with attributes and relations
	#[Route('/metaeditor/addtype/{domain}', name: 'instance_add')]
	public function addMetaType(Request $request, $domain)
	{
		// handle submitted data
		if ($request->getMethod() == 'POST')
		{
			$data = $request->request->all();
			//print_r($data);
			$this->neolib->add_metatype($domain, $data);

			return $this->redirectToRoute('frameN', array('frameName' => 'metaeditor', 'domain' => $domain ));		
		}
		return $this->render('data_editor/type/addtype.html.twig', array(
			'domain' => $domain,
			'listitems' => $this->neolib->get_allMetaTypes()));
	}
	
	// Remove a metaType (Person, Company, etc...)
	#[Route('/metaeditor/deletetype/{domain}/{metaTypeID}', name: 'type_delete')]		
	public function removeMetaType(Request $request, $domain, $metaTypeID)
	{
		$this->neolib->remove_MetaType($domain, $metaTypeID);
		return $this->redirectToRoute('frameN', array('frameName' => 'metaeditor', 'domain' => $domain ));	
	}

	// Add a meta attribute, lookup attribute or relation to an existing type
	#[Route('/metaeditor/metaadd/{domain}/{instanceType}/{metaTypeToAdd}', name: 'metaTypeToAdd')]			
	public function AddMetaThing(Request $request, $domain, $instanceType, $metaTypeToAdd)
	{
		$titprefix = 'Add '.$this->metaNames[$metaTypeToAdd].' to ';

		$metaData = $this->getMetaData('FunctionalType', $metaTypeToAdd);

		$form = $this->getAddEditForm($metaData,[]);
	
		$form->handleRequest($request);			
					
		if ($form->isSubmitted() && $form->isValid()) {
			// get Return values
			$returnValues = $form->getData();
			// Write to Database
			$this->neolib->CreateOrMergeInstance($domain, $instanceType, $metaData, $returnValues, "", array('makedomain' => 'FunctionalType', 'makeMT' => $metaTypeToAdd));
			
			//return new Response('<html><body>|end of stuff</body></html>' );
			return $this->redirectToRoute('frameN', array('frameName' => 'metaeditor', 'domain' => $domain, '', 'metaType'=>$instanceType ));	
		}
		
        return $this->render('data_editor/instance/form.html.twig', [
            'form' => $form->createView(),
			'title'=> $titprefix.$instanceType,
			'redir'=> 'frameN/metaeditor/'.$domain.'/'.$instanceType.'/',
        ]);	
	}

	public function getMetaData($domain, $instanceType)
	{
		$metaData = [];
		// get attribute and relation data  
		$metaData['Attr']=$this->queryResultToArr( $this->neolib->get_typeattr($instanceType, $domain) );
		//Add Name and Description in first position
		array_unshift($metaData['Attr'],['type'=>'textarea','name'=>'description','desc'=>'The description of the '.$instanceType]);
		array_unshift($metaData['Attr'],['type'=>'text','name'=>'name','desc'=>'The name of the '.$instanceType]);		
		//Find Relations
		$metaData['Rel'] = $this->queryResultToArr( $this->neolib->get_metaRels($domain, $instanceType ) ); 
		// replace TaxonomyItem with relation to self (for defining taxonomies)
		for ($i = 0; $i < count($metaData['Rel']); $i++) {
			if ($metaData['Rel'][$i]['name']=='hasParent' && $metaData['Rel'][$i]['totype']=='TaxonomyItem') {
				$metaData['Rel'][$i]['totype']=$instanceType;
				$metaData['Rel'][$i]['domain']=$domain;
			}
		}		
		$metaData['LoAttr'] = $this->queryResultToArr( $this->neolib-> get_metaLookupAttrs($domain, $instanceType) );
		return $metaData;
	}

	private function getAddEditForm($metaData,$instanceData)
	{
		// getAddEditForm start
		$returnValues = [];
		//Create Form Fields	
		$form = $this->createFormBuilder($returnValues)
			->add('save', SubmitType::class, ['label' => 'Save']);		

		$tetc = [
			'text' => TextType::class,
			'textarea' => TextAreaType::class,
			'relation' => ChoiceType::class,
			'datetime-local' => DateTimeType::class,
			'date' => DateType::class,
			'bool' => ChoiceType::class,
			'number' => IntegerType::class,
			'lookupattr' => ChoiceType::class,
		];

		$tetcDisplay = [
			'Text' => 'text',
			'Text (multi-line)' => 'textarea',
			'Datetime' => 'datetime-local',
			'Date' => 'date',
			'Yes/No' => 'bool',
			'Value' => 'number',
			'Time' => 'time',
		];

		// Add Attributes to the form
		foreach($metaData['Attr'] as $item)
		{
			// fill myData with record value
			$myData = '';
			if (array_key_exists('entity',$instanceData)) {
				if (array_key_exists($item['name'],$instanceData['entity'])) { $myData= $instanceData['entity'][$item['name']]; }
			} 
			
			// ... if record value is empty and default value exists, fill that in myData
			if ($myData=='' && array_key_exists('value',$item)) {
				$myData=$item['value'];
			}
			
			if ($item['type']=='bool')
				{
					$form = $form->add($item['name'], $tetc[$item['type']], [ 'required' => true,'label_attr' => ['class' =>'input-group-addon-form'], 'choices' => array('Yes' => true,'No' => false), 'empty_data' => false]);
				} else
				if ($item['name']=='attrtype')
				{	//cheating a little here :-) by making the field named 'attrtype' a combo with all valid types
					$form = $form->add($item['name'], $tetc['lookupattr'], [ 'required' => true,'label_attr' => ['class' =>'input-group-addon-form'], 'choices' => $tetcDisplay, 'empty_data' => false]);
				} else
				{ 	$req = $item['name']=='name' ? true : false;
					$form = $form->add($item['name'], $tetc[$item['type']], [ 'required' => $req,'label_attr' => ['class' =>'input-group-addon-form']]);
				}
				
			if ($item['type']=='datetime-local')
			{
				$date = new \DateTime(str_replace('T',' ',$myData));
				$form->get($item['name'])->setData($date);
			} else				
			if ($item['type']=='bool')
			{
				$tf = false;
				if (gettype($myData)=='string')
				{
					switch ($myData)
					{
						case 'false': $tf = false;
						break;
						case 'true' : $tf = true;
						break;
					}
				} else {$tf = boolval($myData);}
				$form->get($item['name'])->setData($tf);
			} else 
			if ($item['type']=='number')
			{
				$form->get($item['name'])->setData(intval($myData));
			} else
			{ $form->get($item['name'])->setData($myData); }
		}

		// Add Lookups to the form
		foreach($metaData['LoAttr'] as $item)
		{
			// Get choices
			$arr = [];
			$arr = $this->retrieverelnameid($item['relid'], $item['domain'], $item['fromtype'], true);
			$vals = array();
			if (array_key_exists('relprops',$instanceData) && array_key_exists($item['name'],$instanceData['relprops']))
			{
				$chosen = $instanceData['relprops'][$item['name']];
			}
			// Add choice
			$form = $form->add($item['name'], $tetc['lookupattr'], [ 'required' => false,'label_attr' => ['class' =>'input-group-addon-form'], 'choices' => $arr]);
			
			$myData = '';
			if (array_key_exists('entity',$instanceData)) {
				if (array_key_exists($item['name'],$instanceData['entity'])) { $myData= $instanceData['entity'][$item['name']]; }	
			}
			
			if ($myData!='') { $form->get($item['name'])->setData($myData); }
		}		

		// Add Relations to the form
		//dump($metaRelData);
		foreach($metaData['Rel'] as $item)
		{
			// Get choices
			//dump($item);
			$arr = [];
			$arr = $this->retrieverelnameid($item['relid'], $item['domain'], $item['totype'], false);
			$chosen = [];
			$vals = array();
			if (array_key_exists('relprops',$instanceData) && array_key_exists($item['name'],$instanceData['relprops']))
			{
				$chosen = $instanceData['relprops'][$item['name']];
			}
			// Add choice
			$form = $form->add($item['name'], $tetc['relation'], [ 'required' => false,'multiple' => $item['multi'],'label_attr' => ['class' =>'input-group-addon-form'], 'choices' => $arr]);
			if ($item['multi'] == false) // this setData only works for single value selection
			{
				if (sizeof($chosen)>0) { $form->get($item['name'])->setData($chosen[0]); }
			} else // multiple value selection is more difficult (needs array of explicitly created strings)
			{
				if (sizeof($chosen)>0) { $form->get($item['name'])->setData($chosen); }
			}
		}
		dump('upto here');
		return $form=$form->getForm();
	}
	
	// Add or edit an instance of Person, Company, etc...
	#[Route('/dataeditor/edit/{domain}/{instanceType}/{instanceID}', name: 'query_buildertest')]	
    public function AddEditInstance(Request $request, $domain, $instanceType, $instanceID = "")
    {
		$titprefix = $instanceID != "" ? 'Edit ' : 'Add ';
		// get attribute and relation data  
		$metaData = $this->getMetaData($domain, $instanceType);

		//outside getAddEditForm
		$instanceData = [];
		if ($instanceID != "") { // retrieve node information and add to params
			$instanceData = $this->neolib->getInstanceEditData( $domain, $instanceType, $instanceID);
		  }

		$form = $this->getAddEditForm($metaData,$instanceData);
		
		// end getAddEditForm
		$form->handleRequest($request);			
					
		if ($form->isSubmitted() && $form->isValid()) {
			// get Return values
			$returnValues = $form->getData();
			// Write to Database
			$this->neolib->CreateOrMergeInstance($domain, $instanceType, $metaData, $returnValues, $instanceID,[]);
					
			return $this->redirectToRoute('frameN', array('frameName' => 'databrowser', 'domain' => $domain, 'metaType'=>$instanceType));	 // to be implemented: , 'instanceID' => $instanceID (for selection)
		}

        return $this->render('data_editor/instance/form.html.twig', [
            'form' => $form->createView(),
			'title'=> $titprefix.$instanceType,
			'redir'=> '/frameN/databrowser/'.$domain.'/'.$instanceType,
        ]);
    }
	
	private function retrieverelnameid($relid, $domain, $instanceType, $isLoAttr)
	{
		$tti2 = [];
		if ($instanceType != 'instanceType')
		{
			$attrArray = $this->neolib-> get_relattr($relid);
			if (($instanceType=='MetaType') && ($domain =='FunctionalType'))
			{	$totypeinstances = $this->neolib-> get_allMetaTypes();	}
			else {	
				$totypeinstances = $this->neolib-> get_instancenames($domain, $instanceType,0);	
			}
			
			
			if ($isLoAttr) { $tti2 = $this->queryResultToKeyKey($totypeinstances); }
				      else { $tti2 = $this->queryResultToKeyVal($totypeinstances); }
			
		} else
		{   // instanceType triggers return of all types in all domains
			$tti2 = $this->queryResultToKeyVal($this->neolib->get_allinstanceTypes($neocl));
		}
		return $tti2;
	}	
		
	
	// Restructure key,value of the $record into $tti[$key]=$value, with empty values and add that to $attA.
	public function queryResultToArr($queryResult)
	{
		$attA = array();
		$j = 0;
		foreach ($queryResult as $record)
		{
			$thistype = '';
			$thisdefval = '';
			foreach ($record as $key => $val) 
			{
				if ($key != 'defval') {	$tti[$key]= $val; } else { $thisdefval = $val; }
				if ($key == 'type')   { $thistype = $val; }
			}
			
			// set default value
			$tti["value"] = "";
			if ($thisdefval != "")
			{
				if ($thistype == 'Date')             { if ($thisdefval = 'today') { $thisdefval = date('Y-m-d'); }	}
				if ($thistype == 'datetime-local')   { if ($thisdefval = 'today') { $thisdefval = (new \DateTime())->format('Y-m-d\TH:i:s'); }	}
				$tti["value"] = $thisdefval;
			} 
			$attA[$j] = $tti;
			$j++;
		}		
		return $attA;
	}	
	
	// Restructure key,value of the $record into $tti[$key]=$value, with empty values and add that to $attA.
	public function queryResultToKeyVal($queryResult)
	{
		$attA = [];
		foreach ($queryResult as $record)
		{
			$name = $record['name'];
			$id = $record['id'];
			$attA[$name.' - ('.$id.')'] = $id;
		}		
		return $attA;
	}
	
	// Restructure key,value of the $record into $tti[$key]=$key, so the name of the relation is returned for LookupAttributes
	public function queryResultToKeyKey($queryResult)
	{
		$attA = [];
		foreach ($queryResult as $record)
		{
			$name = $record['name'];
			$attA[$name] = $name;
		}		
		return $attA;
	}	

}
