<?php

namespace App\Controller;

use App\Manager\PokerManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class UploadController extends AbstractController
{

    const REGEX = "/^
    (?<FULL_HAND>Winamax\sPoker\s-\s(?<NAME>.+?)\s-\s(?<HAND_ID>.+?)
    Table:\s(?<TABLE>.+?)\s(?<BUTTON>Seat\s\#\d+\sis\sthe\sbutton)\n
    (?<REST>.*?)
    \n\n\n)
    $/mxs";

    #[Route('/upload', name: 'app_upload_form', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('upload/form.html.twig', []);
    }

    #[Route('/upload', name: 'app_upload_view', methods: ['post'])]
    public function view(RequestStack $requestStack, PokerManager $pokerManager): Response
    {
        /** @var UploadedFile */
        $uploadedFile = $requestStack->getCurrentRequest()->files->get('poker_history');
        $file = $pokerManager->getDataFromFile($uploadedFile);
        return $this->render('upload/view.html.twig', [
            'file' => $file,
        ]);
    }

    private function ExtractData(UploadedFile $file)
    {
        $START = '/^Winamax Poker - (?<GAME>.*) - HandId: (?<HANDID>.*) - (?<VARIANT>.*) - (?<DATE>.*)$/';
        $TABLE = '/^Table: (?<TABLE>.*) (?<BUTTON>Seat.*)$/';
        $SEAT = '/^Seat (?<SEAT>\d+): (?<NAME>.*) \((?<STACK>.*)\)$/';
        $DEALT = '/^Dealt to (?<NAME>.*) \[(?<CARDS>.*)\]$/';
        $STEP = '/\*\*\* (?<STEP>.*) \*\*\*( .*)?$/';
        $ACTION = '/^
        (?<NAME>.*)\s
        (?<ACTION>posts\sante|posts\ssmall\sblind|posts\sbig\sblind|folds|checks|calls|bets|raises|collected)
        \s?
        (?<VALUE>\d+)?
        (\sto\s(?<TO>\d+))?
        (\s(?<POT>from\s(side|main)?\s?pot(\s\d+)?)?)?
        (\s(?<ALLIN>and\sis\sall-in))?
        $/x';
        $SHOW = '/^(?<NAME>.*) (?<ACTION>shows) \[(?<CARDS>.*)\] \((?<HAND>.*)\)$/';
        $BOARD = '/^Board: \[(?<BOARD>.*)\]$/';
        $TOTAL = '/^Total pot (?<TOTAL>.*) \| (?<RAKE>.*)$/';
        $SUMMARY = '/^Seat (?<SEAT>\d+): (?<NAME>.*)( \((?<BUTTON>button)\))? (?<ACTION>won|showed)( (?<VALUE>\d+))?( \[(?<HAND>.*)\] and (?<RESULT>.*))?$/';
        $END = '/^\n$/';
        $data = [];
        $currentHand = [];
        $arrayFile = file($file->getPathname());
        $currentStep = "SEATED";
        foreach ($arrayFile as $line) {
            if (preg_match($START, $line, $match) === 1) {
                $currentHand = [
                    'unmatched_lines' => [],
                    'seats' => [],
                    'GAME' => $match['GAME'],
                    'HANDID' => $match['HANDID'],
                    'VARIANT' => $match['VARIANT'],
                    'DATE' => $match['DATE'],
                ];
            } elseif (preg_match($TABLE, $line, $match) === 1) {
                $currentHand['TABLE'] = $match['TABLE'];
                $currentHand['BUTTON'] = $match['BUTTON'];
            } elseif (preg_match($SEAT, $line, $match) === 1) {
                $currentHand['seats'][] = [
                    'SEAT' => $match['SEAT'],
                    'NAME' => $match['NAME'],
                    'STACK' => $match['STACK'],
                ];
            } elseif (preg_match($STEP, $line, $match) === 1) {
                $currentStep = $match['STEP'];
                $currentHand[$currentStep] = [];
            } elseif (preg_match($DEALT, $line, $match) === 1) {
                $currentHand['hero'] = [
                    'NAME' => $match['NAME'],
                    'CARDS' => $match['CARDS'],
                ];
            } elseif (preg_match($ACTION, $line, $match) === 1) {
                $currentHand[$currentStep][] = [
                    'NAME' => $match['NAME'],
                    'ACTION' => $match['ACTION'],
                    'VALUE' => $match['VALUE'] ?? null,
                    'TO' => $match['TO'] ?? null,
                    'POT' => $match['POT'] ?? null,
                    'ALLIN' => isset($match['ALLIN']),
                ];
            } elseif (preg_match($SHOW, $line, $match) === 1) {
                $currentHand[$currentStep][] = [
                    'NAME' => $match['NAME'],
                    'ACTION' => $match['ACTION'],
                    'CARDS' => $match['CARDS'],
                    'HAND' => $match['HAND'],
                ];
            } elseif (preg_match($TOTAL, $line, $match) === 1) {
                $currentHand['total'] = $match['TOTAL'];
                $currentHand['rake'] = $match['RAKE'];
            } elseif (preg_match($BOARD, $line, $match) === 1) {
                $currentHand['board'] = $match['BOARD'];
            } elseif (preg_match($SUMMARY, $line, $match) === 1) {
                $currentHand[$currentStep][] = [
                    'SEAT' => $match['SEAT'],
                    'NAME' => $match['NAME'],
                    'ACTION' => $match['ACTION'],
                    'BUTTON' => isset($match['BUTTON']),
                    'VALUE' => $match['VALUE'] ?? null,
                    'HAND' => $match['HAND'] ?? null,
                    'RESULT' => $match['RESULT'] ?? null,
                ];
            } elseif (preg_match($END, $line) === 1) {
                if ($currentHand != []) {
                    $data[] = $currentHand;
                }
                $currentHand = [];
            } else {
                $currentHand['unmatched_lines'][] = $line;
            }
        }

        return  [
            'name' => $file->getClientOriginalName(),
            'content' => $file->getContent(),
            'data' => $data
        ];
    }
}
