<?php

namespace AppBundle\Controller;

use AppBundle\Entity\ClipperActivity;
use AppBundle\Entity\IssueMedia;
use AppBundle\Manager\FileManager;
use DateTime;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class SomeController
 * @package AppBundle\Controller
 *
 * @Route(path="/some_page")
 */
class SomeController extends BaseController
{
    /**
     * @Route(path="/clipper_activity", name="clipper_activity", options={"expose": true})
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function clipperActivityAction(Request $request)
    {
        $em = $this->getEm();
        $user = $this->getCurrentUser();
        $type = $request->get('type');

        switch ($type) {
            case 'start_shift':
                $clipperActivity = new ClipperActivity();

                $clipperActivity->setUser($user);
                $clipperActivity->setType('shift');
                $clipperActivity->setEndAt(null);

                $em->persist($clipperActivity);
                $em->flush();

                break;
            case 'start_break':
                $clipperActivity = new ClipperActivity();

                $clipperActivity->setUser($user);
                $clipperActivity->setType('break');
                $clipperActivity->setEndAt(null);

                $em->persist($clipperActivity);
                $em->flush();

                break;
            case 'end_shift':
                $qb = $em->createQueryBuilder();

                $qb->select('clipperActivity')
                   ->from('AppBundle:ClipperActivity', 'clipperActivity')
                   ->where($qb->expr()->eq('clipperActivity.user', $user->getId()))
                   ->andWhere($qb->expr()->isNull('clipperActivity.endAt'))
                   ->andWhere($qb->expr()->eq('clipperActivity.type', $qb->expr()->literal('shift')))
                   ->setMaxResults(1);

                /** @var ClipperActivity $openShift */
                $openShift = $qb->getQuery()->getSingleResult();

                $openShift->setEndAt(new DateTime());
                $em->persist($openShift);
                $em->flush();

                $qb = $em->createQueryBuilder();

                $qb->select('clipperActivity')
                   ->from('AppBundle:ClipperActivity', 'clipperActivity')
                   ->where($qb->expr()->eq('clipperActivity.user', $user->getId()))
                   ->andWhere($qb->expr()->isNull('clipperActivity.endAt'))
                   ->andWhere($qb->expr()->eq('clipperActivity.type', $qb->expr()->literal('break')))
                   ->setMaxResults(1);

                if ($qb->getQuery()->getResult()) {
                    /** @var ClipperActivity $openBreak */
                    $openBreak = $qb->getQuery()->getSingleResult();

                    $openBreak->setEndAt(new DateTime());
                    $em->persist($openBreak);
                    $em->flush();
                }

                break;
            case 'end_break':
                $qb = $em->createQueryBuilder();

                $qb->select('clipperActivity')
                   ->from('AppBundle:ClipperActivity', 'clipperActivity')
                   ->where($qb->expr()->eq('clipperActivity.user', $user->getId()))
                   ->andWhere($qb->expr()->isNull('clipperActivity.endAt'))
                   ->andWhere($qb->expr()->eq('clipperActivity.type', $qb->expr()->literal('break')))
                   ->setMaxResults(1);

                /** @var ClipperActivity $openBreak */
                $openBreak = $qb->getQuery()->getSingleResult();

                $openBreak->setEndAt(new DateTime());
                $em->persist($openBreak);
                $em->flush();

                break;
            default:
                break;
        }

        return new JsonResponse();
    }

    /**
     * @Route(path="/highlight_articles", name="highlight_articles", options={ "expose": true })
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function highlightArticlesAction(Request $request) {
        $issueMediaId = $request->get('issueMediaId');
        $em = $this->getEm();

        /** @var IssueMedia $issueMedia */
        $issueMedia = $em->getRepository('AppBundle:IssueMedia')->find($issueMediaId);
        $fileManager = $this->get(FileManager::class);

        $xmlMedia = $issueMedia->getXmlMedia();
        $image = $issueMedia->getMedia();

        if ($xmlMedia) {
            $xmlPath = $fileManager->getAbsolutePath($xmlMedia);
            $imagePath = $fileManager->getAbsolutePath($image);

            $requestData = [
                'xml_path' => $xmlPath,
                'image_path' => $imagePath
            ];

            if ($this->get('kernel')->getEnvironment() === 'prod') {
                $ip = 'python';
            } else {
                $ip = $this->getParameter('python_server_host');
            }

            $client = new Client();
            $response = $client->post('http://' . $ip . ':1020/api/v1', [
                'json' => $requestData
            ]);

            $result = json_decode($response->getBody(), true);

        } else {
            $result = [
                'type'    => 'danger',
                'message' => '<b>Error!</b> Articles not found.'
            ];
        }

        return new JsonResponse($result);
    }
}
