<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdateCommand extends ContainerAwareCommand
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    protected function configure()
    {
        $this
            ->setName('project:update')
            ->setDescription('Update data')->setHelp('Helpful command for update different sorts of data')
            ->addArgument(
                'method',
                InputArgument::REQUIRED,
                'What method do you want execute?'
            );
    }

    public function cleanupTwitterUrls()
    {
        $i = 0;
        $offset = 0;
        $em = $this->getContainer()->get('doctrine')->getEntityManager();

        $qb = $em->createQueryBuilder();

        $qb->select('COUNT(website.id)')
           ->from('AppBundle:ContactWebsite', 'website')
           ->andWhere($qb->expr()->in('website.type', [
               ContactWebsitePeer::TYPE_PERSON_TWITTER,
               ContactWebsitePeer::TYPE_TWITTER,
           ]));

        $total = $qb->getQuery()->getSingleScalarResult();

        $this->io->title('Total count: ' . $total);

        do {
            $qb = $em->createQueryBuilder();

            $qb->select('website')
               ->from('AppBundle:ContactWebsite', 'website')
               ->andWhere($qb->expr()->in('website.type', [
                   ContactWebsitePeer::TYPE_PERSON_TWITTER,
                   ContactWebsitePeer::TYPE_TWITTER,
               ]))
               ->setMaxResults(100)
               ->setFirstResult($offset);

            $twitters = $qb->getQuery()->getResult();

            /** @var ContactWebsite $twitter */
            foreach ($twitters as $twitter) {
                $oldUrl = $twitter->getUrl();
                $twitter->setUrl(preg_replace(['/#!\//', '/\?.*/'], '', $oldUrl));
                $newUrl = $twitter->getUrl();
                $em->persist($twitter);
                $i++;
                $this->io->text($i . '/' . $total . ' id ' . $twitter->getId() . ' ' . $oldUrl . ' - ' . $newUrl);
            }

            $em->flush();
            $em->clear();

            $offset += 100;

        } while ($twitters);

        $this->io->success('Complete!');
    }

    public function importSearchTermsChanges()
    {
        $this->io->writeln('Start command');

        /** @var EntityManager $em */
        $em = $this->getContainer()->get('doctrine')->getManager();

        $files = glob('docs/slack_history/*.json');
        $count = count($files);

        foreach ($files as $key => $file) {
            $fileContent = file_get_contents($file);
            $json = json_decode($fileContent, true);

            foreach ($json as $item) {
                $text = $item['text'];
                $creatorContact = null;
                $profile = null;

                if (isset($text, $item['username']) && $item['username'] === 'Monitoring Bot') {
                    preg_match('/^(.*?) has changed/i', $text, $creatorName);
                    if (count($creatorName)) {
                        $qb = $em->createQueryBuilder();
                        $expr = $qb->expr();
                        $qb->select('contact')
                           ->from('AppBundle:Contact', 'contact')
                           ->leftJoin('AppBundle:User', 'user', Join::WITH, 'user.contact = contact')
                           ->where($expr->eq('contact.title', $expr->literal($creatorName[1])))
                           ->andWhere($expr->in('user.account', [AccountPeer::ID_ACCOUNT, AccountPeer::ID_ANOTHER_ACCOUNT]))
                           ->setMaxResults(1);

                        $creatorContact = $qb->getQuery()->getOneOrNullResult();
                    }

                    preg_match('/has changed (.*?) profile/i', $text, $profileName);
                    if (count($profileName)) {
                        $qb = $em->createQueryBuilder();
                        $expr = $qb->expr();
                        $qb->select('profile')
                           ->from('AppBundle:Profile', 'profile')
                           ->where($expr->eq('profile.title', $expr->literal($profileName[1])))
                           ->andWhere($expr->in('profile.account', [AccountPeer::ID_ACCOUNT, AccountPeer::ID_ANOTHER_ACCOUNT]))
                           ->setMaxResults(1);

                        /** @var Profile $profile */
                        $profile = $qb->getQuery()->getOneOrNullResult();
                    }

                    preg_match('/included content: (.*?)\\n/i', $text, $content);
                    preg_match('/instructions: (.*?)\\n/i', $text, $instructions);
                    preg_match('/covered countries: (.*?)\\n/i', $text, $countries);
                    preg_match('/specific outlets: (.*?)\\n/i', $text, $outlets);
                    preg_match('/ignore passing mentions: (.*?)\\n/i', $text, $passing);
                    preg_match('/and terms: (\\n|)(.*?)(\\n|)(or terms|not terms|$)/is', $text, $andTerms);
                    preg_match('/or terms: (\\n|)(.*?)(\\n|)(not terms|$)/is', $text, $orTerms);
                    preg_match('/not terms: (\\n|)(.*?)\\n/is', $text, $notTerms);

                    if ($profile) {
                        if (count($content)) {
                            $this->createProfileLog((int)$item['ts'], 'Content', $content[1], $profile, $creatorContact);
                        }

                        if (count($instructions)) {
                            $this->createProfileLog((int)$item['ts'], 'Instructions', $instructions[1], $profile, $creatorContact);
                        }

                        if (count($countries)) {
                            $this->createProfileLog((int)$item['ts'], 'Countries ids', $countries[1], $profile, $creatorContact);
                        }

                        if (count($outlets)) {
                            $this->createProfileLog((int)$item['ts'], 'Outlets ids', $outlets[1], $profile, $creatorContact);
                        }

                        $isCaseSensitive = '';
                        if (count($andTerms) === 0 && count($orTerms) === 0 && count($notTerms) === 0) {
                            preg_match('/case sensitive: (.*?)\\n/i', $text, $case);
                            if ($case) {
                                if ($case[1] === 'YES') {
                                    $isCaseSensitive = '1';
                                } else {
                                    $isCaseSensitive = '0';
                                }
                            }
                        } else {
                            $firstPart = preg_replace('/(and terms.*|or terms.*|not terms.*)/is', '', $text);
                            preg_match('/case sensitive: (.*?)\\n/i', $firstPart, $case);
                            if ($case) {
                                if ($case[1] === 'YES') {
                                    $isCaseSensitive = '1';
                                } else {
                                    $isCaseSensitive = '0';
                                }
                            }
                        }

                        if ($isCaseSensitive) {
                            $this->createProfileLog((int)$item['ts'], 'Is case sensitive', $isCaseSensitive, $profile, $creatorContact);
                        }

                        if (count($passing)) {
                            if ($passing[1] === 'YES') {
                                $isPassingMentions = '1';
                            } else {
                                $isPassingMentions = '0';
                            }

                            $this->createProfileLog((int)$item['ts'], 'Is one pass match', $isPassingMentions, $profile, $creatorContact);
                        }

                        if (count($andTerms)) {
                            $newValue = '';
                            $terms = explode("\n", $andTerms[2]);

                            foreach ($terms as $term) {
                                $caseSensitivity = '';
                                preg_match('/(.*?)\./i', $term, $keyword);
                                preg_match('/case sensitive: (.*?)$/is', $term, $case);

                                if (count($case)) {
                                    if ($case[1] === 'YES') {
                                        $caseSensitivity = '1';
                                    } else {
                                        $caseSensitivity = '0';
                                    }
                                }

                                if (count($keyword)) {
                                    $keywordTitle = $keyword[1];
                                } else {
                                    $keywordTitle = $term;
                                }

                                $newValue .= 'Keyword:' . $keywordTitle . ', False positives:, Case sensitivity:' . $caseSensitivity . '. ';
                            }

                            $this->createProfileLog((int)$item['ts'], 'Terms and', $newValue, $profile, $creatorContact);
                        }

                        if (count($orTerms)) {
                            $newValue = '';
                            $terms = explode("\n", $orTerms[2]);

                            foreach ($terms as $term) {
                                $caseSensitivity = '';
                                preg_match('/(.*?)\./i', $term, $keyword);
                                preg_match('/case sensitive: (.*?)$/is', $term, $case);

                                if (count($case)) {
                                    if ($case[1] === 'YES') {
                                        $caseSensitivity = '1';
                                    } else {
                                        $caseSensitivity = '0';
                                    }
                                }

                                if (count($keyword)) {
                                    $keywordTitle = $keyword[1];
                                } else {
                                    $keywordTitle = $term;
                                }

                                $newValue .= 'Keyword:' . $keywordTitle . ', False positives:, Case sensitivity:' . $caseSensitivity . '. ';
                            }

                            $this->createProfileLog((int)$item['ts'], 'Terms or', $newValue, $profile, $creatorContact);
                        }

                        if (count($notTerms)) {
                            $newValue = '';
                            $terms = explode("\n", $notTerms[2]);

                            foreach ($terms as $term) {
                                $caseSensitivity = '';
                                preg_match('/(.*?)\./i', $term, $keyword);
                                preg_match('/case sensitive: (.*?)$/is', $term, $case);

                                if (count($case)) {
                                    if ($case[1] === 'YES') {
                                        $caseSensitivity = '1';
                                    } else {
                                        $caseSensitivity = '0';
                                    }
                                }

                                if (count($keyword)) {
                                    $keywordTitle = $keyword[1];
                                } else {
                                    $keywordTitle = $term;
                                }

                                $newValue .= 'Keyword:' . $keywordTitle . ', False positives:, Case sensitivity:' . $caseSensitivity . '. ';
                            }

                            $this->createProfileLog((int)$item['ts'], 'Terms not', $newValue, $profile, $creatorContact);
                        }

                        $em->flush();
                        $em->clear();
                    }
                }
            }

            $this->io->writeln(basename($file) . ' (' . ($key + 1) . '/' . $count . ')');
            $this->io->newLine();
        }

        $this->io->success(['End command']);
    }

    private function createProfileLog($createdAt, $fieldName, $newValue, $profile, $creatorContact)
    {
        /** @var EntityManager $em */
        $em = $this->getContainer()->get('doctrine')->getManager();

        $previousLog = $em->getRepository('AppBundle:ProfileLog')->findOneBy([
            'creatorContact' => $creatorContact ? $creatorContact->getId() : null,
            'profile'        => $profile->getId(),
            'field'          => ProfileLog::FIELD_NAME_PARAMETERS . '. ' . $fieldName
        ], ['id' => 'DESC']);

        if (!$previousLog || $previousLog->getNewValue() !== $newValue) {
            $profileLog = new ProfileLog();
            $profileLog->setCreatedAt($createdAt);
            $profileLog->setField(ProfileLog::FIELD_NAME_PARAMETERS . '. ' . $fieldName);
            $profileLog->setType(ProfileLog::ACTION_CHANGE);
            $profileLog->setNewValue($newValue);
            $profileLog->setProfile($profile);

            if ($creatorContact) {
                $profileLog->setCreatorContact($creatorContact);
            }

            $em->persist($profileLog);
        }

    }
}
