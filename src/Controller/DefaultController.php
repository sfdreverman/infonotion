<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\NeoService;



class DefaultController extends AbstractController
{
    /**
     * @Route("/", name="homepage")
     */
    #[Route('/', name: 'homepage')]
    public function indexAction(Request $request)
    {
        // Fetch TopNavTree here and stream into index (fill sidebar)
		$neolib = new NeoService();
		$data = $neolib->get_domains('TopNavTree');
        
        return $this->render('default/index.html.twig',array(
			'domains' => $data,
			'subtit' => 'Main Menu') );
    }
}