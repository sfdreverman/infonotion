<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\NeoService;

class QuestionController extends AbstractController
{
	private $neolib = null;
	
    public function __construct()
    {
        $this->neolib = new NeoService();
    }	
    
	#[Route('/questionnaire/{instanceID}', name: 'questionnaire')]
    public function AnswerQuestions(Request $request, $instanceID)
    {
        // Redirect to the frame that answers the question
		return $this->redirectToRoute('frameN', array('frameName' => 'Questionnaire', 'domain' => 'Questions', 'metaType'=>'ListOfQuestions'));	
		//return $this->redirect('/frameN/Questionnaire/Questions/ListOfQuestions/'.$instanceID);
    }
	
	
	/**
	 * The Json calls that load repsonses (StoreRespondent, GetNextQuestion)
	 */
	 
	 // JSONifies the HTML render for transport to frontend
	 #[Route('/j_getRespondentInfo/{RespondentName}/{LoQID}', name: 'respondent_info')]	 
    public function getRespondentInfo(Request $request, $RespondentName, $LoQID)
	{
		$query = 'MATCH (q:Question)<-[:contains]-(loq:ListOfQuestions) where loq.in_id=$LoQID WITH loq,q MATCH (r:Respondent) where r.name=$RespondentName OPTIONAL MATCH (r)-[rw:respondedWith]->(a:Answer)-[:isAnswerTo]->(q) return r.in_id as RespondentID, r.name as RespondentName , q.order as QNumber, q.name as Question, a.name as Answer ORDER BY QNumber';
		$theData = $this->neolib->QueryToArr($query, ['RespondentName' => $RespondentName,'LoQID' => $LoQID]);
		dump($theData);
		return new JsonResponse( $theData );
	}
	 
	// Get the render of one view
    // JSONifies the HTML render for transport to frontend	 
	#[Route('/j_getnextquestion/{RespondentID}/{LoQID}/{AnswerID}', name: 'questionnaire_respondent_info')]	 	 
    public function getHTMLQuestionData(Request $request, $RespondentID, $LoQID, $AnswerID = "")
    {
		return new JsonResponse(
					array("code" => 200, 
						  "response" => $this->getNextQuestion($RespondentID, $LoQID, $AnswerID)));
	}	 
	 
	public function getNextQuestion($RespondentID, $LoQID, $AnswerID)
	{			
		// if an AnswerID is given, store it.
		if (!$AnswerID=="")
		{
			//SetAnswer
			$query = 'MATCH (r:Respondent),(a:Answer) where r.in_id=$RespondentID and a.in_id=$AnswerID MERGE (r)-[:respondedWith]->(a)';
			$this->neolib->QueryToArr($query, ['RespondentID' => $RespondentID,'AnswerID' => $AnswerID]);
		}
		// Todo: Address this hardcoded View!!!
		$ViewData = $this->neolib->getView('View5ee536863ee7e7.13505371', false);
		$query = $ViewData['view']['query'];
		$theData = $this->neolib->QueryToArr($query, ['RespondentID' => $RespondentID,'LoQID' => $LoQID]);
		//dump($theData);
		$resultArray = array(
			'RespondentID' => $RespondentID,
			'LoQID' => $LoQID,
			'Question' => $theData,
			'theView' => $ViewData['view']);
		
        return $this->render('view/templates/'.$ViewData['view']['templateName'], $resultArray)->getContent();		
		
		return new JsonResponse( $tabledData );
	}
	 
}