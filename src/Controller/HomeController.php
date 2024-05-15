<?php

namespace App\Controller;

use App\Attributes\EncryptFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


class HomeController extends AbstractController
{

    #[Route(path: '/home', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home.html.twig');
    }

    #[Route(path: '/upload', name: 'upload_route', methods: ['POST'])]
    public function uploadFile(
        #[EncryptFile()] string $file
    ):Response {
        dd($file);
    }
}