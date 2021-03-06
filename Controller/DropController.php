<?php
/**
 * Created by : Vincent SAISSET
 * Date: 22/08/13
 * Time: 09:30
 */

namespace Innova\CollecticielBundle\Controller;

use Innova\CollecticielBundle\Entity\Correction;
use Innova\CollecticielBundle\Entity\Drop;
use Innova\CollecticielBundle\Entity\Dropzone;
use Innova\CollecticielBundle\Event\Log\LogCorrectionUpdateEvent;
use Innova\CollecticielBundle\Event\Log\LogDropEndEvent;
use Innova\CollecticielBundle\Event\Log\LogDropStartEvent;
use Innova\CollecticielBundle\Event\Log\LogDropReportEvent;
use Innova\CollecticielBundle\Form\CorrectionReportType;
use Innova\CollecticielBundle\Form\DropType;
use Innova\CollecticielBundle\Form\DocumentType;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Library\Resource\ResourceCollection;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class DropController extends DropzoneBaseController
{

    /**
     * @Route(
     *      "/{resourceId}/drop",
     *      name="innova_collecticiel_drop",
     *      requirements={"resourceId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Participate in an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function dropAction(Dropzone $dropzone, User $user)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);

        $em = $this->getDoctrine()->getManager();
        $dropRepo = $em->getRepository('InnovaCollecticielBundle:Drop');

        if ($dropRepo->findOneBy(array('dropzone' => $dropzone, 'user' => $user, 'finished' => true)) !== null) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('You ve already made ​​your copy for this review', array(), 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    array(
                        'resourceId' => $dropzone->getId()
                    )
                )
            );
        }

        $notFinishedDrop = $dropRepo->findOneBy(array('dropzone' => $dropzone, 'user' => $user, 'finished' => false));
        if ($notFinishedDrop === null) {
            $notFinishedDrop = new Drop();
            $number = ($dropRepo->getLastNumber($dropzone) + 1);
            $notFinishedDrop->setNumber($number);

            $notFinishedDrop->setUser($user);
            $notFinishedDrop->setDropzone($dropzone);
            $notFinishedDrop->setFinished(false);

            $em->persist($notFinishedDrop);
            $em->flush();
            $em->refresh($notFinishedDrop);

            $event = new LogDropStartEvent($dropzone, $notFinishedDrop);
            $this->dispatch($event);
        }

        $form = $this->createForm(new DropType(), $notFinishedDrop);
        $form_url = $this->createForm(new DocumentType(), null, array('documentType' => 'url'));
        $form_file = $this->createForm(new DocumentType(), null, array('documentType' => 'file'));
        $form_resource = $this->createForm(new DocumentType(), null, array('documentType' => 'resource'));
        $form_text = $this->createForm(new DocumentType(), null, array('documentType' => 'text'));
        $drop = $notFinishedDrop;

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());

            if (count($notFinishedDrop->getDocuments()) == 0) {
                $form->addError(new FormError('Add at least one document'));
            }

            if ($form->isValid()) {
                // Début InnovaERV : vu avec Donovan //
                // On ne ferme plus le collecticiel ici. //
                // Par contre, on ne touche pas ailleurs, notamment lors de la fermeture automatiquement d'un collecticiel. //
                // $notFinishedDrop->setFinished(true);
                // fin InnovaERV :  //

                $em = $this->getDoctrine()->getManager();
                $em->persist($notFinishedDrop);
                $em->flush();

                $rm = $this->get('claroline.manager.role_manager');
                $event = new LogDropEndEvent($dropzone, $notFinishedDrop, $rm);
                $this->dispatch($event);

                $this->getRequest()->getSession()->getFlashBag()->add(
                    'success',
                    $this->get('translator')->trans('Your copy has been saved', array(), 'innova_collecticiel')
                );

                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_open',
                        array(
                            'resourceId' => $dropzone->getId()
                        )
                    )
                );
            }
        }

        $allowedTypes = array();
        if ($dropzone->getAllowWorkspaceResource()) $allowedTypes[] = 'resource';
        if ($dropzone->getAllowUpload()) $allowedTypes[] = 'file';
        if ($dropzone->getAllowUrl()) $allowedTypes[] = 'url';
        if ($dropzone->getAllowRichText()) $allowedTypes[] = 'text';

        $resourceTypes = $this->getDoctrine()->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findAll();

        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $dropzoneProgress = $dropzoneManager->getDropzoneProgressByUser($dropzone, $user);

//        $adminInnova = $this->get('innova.manager.collecticiel_manager')->adminOrNot($user);

        $adminInnova = false;
        if ( $this->get('security.authorization_checker')->isGranted('ROLE_ADMIN' === true)
        && $this->get('security.token_storage')->getToken()->getUser()->getId() == $user->getId()) {
            $adminInnova = true;
        }
        
        // Déclarations des nouveaux tableaux, qui seront passés à la vue
        $userNbTextToRead = array();

        return array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'drop' => $drop,
            'form' => $form->createView(),
            'form_url' => $form_url->createView(),
            'form_file' => $form_file->createView(),
            'form_resource' => $form_resource->createView(),
            'form_text' => $form_text->createView(),
            'allowedTypes' => $allowedTypes,
            'resourceTypes' => $resourceTypes,
            'dropzoneProgress' => $dropzoneProgress,
            'adminInnova' => $adminInnova,
            'userNbTextToRead' => $userNbTextToRead,
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/drop/{userId}",
     *      name="innova_collecticiel_drop_switch",
     *      requirements={"resourceId" = "\d+", "userId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("user",class="ClarolineCoreBundle:User",options={"id" = "userId"})
     * @Template("InnovaCollecticielBundle:Drop:drop.html.twig"))
     */
    public function dropSwitchAction(Dropzone $dropzone, User $user)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);

        $em = $this->getDoctrine()->getManager();
        $dropRepo = $em->getRepository('InnovaCollecticielBundle:Drop');

        if ($dropRepo->findOneBy(array('dropzone' => $dropzone, 'user' => $user, 'finished' => true)) !== null) {
            $this->getRequest()->getSession()->getFlashBag()->add(
                'error',
                $this->get('translator')->trans('You ve already made ​​your copy for this review', array(), 'innova_collecticiel')
            );

            return $this->redirect(
                $this->generateUrl(
                    'innova_collecticiel_open',
                    array(
                        'resourceId' => $dropzone->getId()
                    )
                )
            );
        }

        $notFinishedDrop = $dropRepo->findOneBy(array('dropzone' => $dropzone, 'user' => $user, 'finished' => false));
        if ($notFinishedDrop === null) {
            $notFinishedDrop = new Drop();
            $number = ($dropRepo->getLastNumber($dropzone) + 1);
            $notFinishedDrop->setNumber($number);

            $notFinishedDrop->setUser($user);
            $notFinishedDrop->setDropzone($dropzone);
            $notFinishedDrop->setFinished(false);

            $em->persist($notFinishedDrop);
            $em->flush();
            $em->refresh($notFinishedDrop);

            $event = new LogDropStartEvent($dropzone, $notFinishedDrop);
            $this->dispatch($event);
        }

        $form = $this->createForm(new DropType(), $notFinishedDrop);
        $form_url = $this->createForm(new DocumentType(), null, array('documentType' => 'url'));
        $form_file = $this->createForm(new DocumentType(), null, array('documentType' => 'file'));
        $form_resource = $this->createForm(new DocumentType(), null, array('documentType' => 'resource'));
        $form_text = $this->createForm(new DocumentType(), null, array('documentType' => 'text'));
        $drop = $notFinishedDrop;

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());

            if (count($notFinishedDrop->getDocuments()) == 0) {
                $form->addError(new FormError('Add at least one document'));
            }

            if ($form->isValid()) {
                // Début InnovaERV : vu avec Donovan //
                // On ne ferme plus le collecticiel ici. //
                // Par contre, on ne touche pas ailleurs, notamment lors de la fermeture automatiquement d'un collecticiel. //
                // $notFinishedDrop->setFinished(true);
                // fin InnovaERV :  //

                $em = $this->getDoctrine()->getManager();
                $em->persist($notFinishedDrop);
                $em->flush();

                $rm = $this->get('claroline.manager.role_manager');
                $event = new LogDropEndEvent($dropzone, $notFinishedDrop, $rm);
                $this->dispatch($event);

                $this->getRequest()->getSession()->getFlashBag()->add(
                    'success',
                    $this->get('translator')->trans('Your copy has been saved', array(), 'innova_collecticiel')
                );

                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_open',
                        array(
                            'resourceId' => $dropzone->getId()
                        )
                    )
                );
            }
        }

        $allowedTypes = array();
        if ($dropzone->getAllowWorkspaceResource()) $allowedTypes[] = 'resource';
        if ($dropzone->getAllowUpload()) $allowedTypes[] = 'file';
        if ($dropzone->getAllowUrl()) $allowedTypes[] = 'url';
        if ($dropzone->getAllowRichText()) $allowedTypes[] = 'text';

        $resourceTypes = $this->getDoctrine()->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findAll();

        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $dropzoneProgress = $dropzoneManager->getDropzoneProgressByUser($dropzone, $user);

//        $adminInnova = $this->get('innova.manager.collecticiel_manager')->adminOrNot($user);

        $adminInnova = false;
        if ( $this->get('security.authorization_checker')->isGranted('ROLE_ADMIN' === true)
        && $this->get('security.token_storage')->getToken()->getUser()->getId() == $user->getId()) {
            $adminInnova = true;
        }
        
        // Déclarations des nouveaux tableaux, qui seront passés à la vue
        $userNbTextToRead = array();

        return array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'drop' => $drop,
            'form' => $form->createView(),
            'form_url' => $form_url->createView(),
            'form_file' => $form_file->createView(),
            'form_resource' => $form_resource->createView(),
            'form_text' => $form_text->createView(),
            'allowedTypes' => $allowedTypes,
            'resourceTypes' => $resourceTypes,
            'dropzoneProgress' => $dropzoneProgress,
            'adminInnova' => $adminInnova,
            'userNbTextToRead' => $userNbTextToRead,
        );
    }

    private function addDropsStats($dropzone, $array)
    {
        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $array['nbDropCorrected'] = $dropRepo->countDropsFullyCorrected($dropzone);
        $array['nbDrop'] = $dropRepo->countDrops($dropzone);

        return $array;
    }


    /**
     *
     * @Route(
     *      "/{resourceId}/drops/by/user",
     *      name="innova_collecticiel_drops_by_user",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/by/user/{page}",
     *      name="innova_collecticiel_drops_by_user_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function dropsByUserAction($dropzone, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $dropsQuery = $dropRepo->getDropsFullyCorrectedOrderByUserQuery($dropzone);

        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());
        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_by_user_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        return $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'unterminated_drops' => $countUnterminatedDrops,
            'dropzone' => $dropzone,
            'pager' => $pager
        ));
    }

    /**
     * @Route(
     *      "/{resourceId}/unlock/{userId}",
     *      name="innova_collecticiel_unlock_user",
     *      requirements={"resourceId" = "\d+", "userId" = "\d+"}
     * )
     * @ParamConverter("dropzone",class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     *
     * @param \Innova\CollecticielBundle\Entity\Dropzone $dropzone
     * @param $userId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @internal param $user
     * @internal param $userId
     */
    public function unlockUser(Dropzone $dropzone, $userId)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);
        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $drop = $dropRepo->getDropByUser($dropzone->getId(), $userId);
        if ($drop != null) {
            $drop->setUnlockedUser(true);
        }
        $em = $this->getDoctrine()->getManager();
        $em->merge($drop);
        $em->flush();

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_examiners',
                array(
                    'resourceId' => $dropzone->getId()
                )
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/unlock/all",
     *      name="innova_collecticiel_unlock_all_user",
     *      requirements={"resourceId" = "\d+"}
     * )
     * @ParamConverter("dropzone",class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     *
     * @param \Innova\CollecticielBundle\Entity\Dropzone $dropzone
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @internal param $user
     * @internal param $userId
     */
    public function unlockUsers(Dropzone $dropzone)
    {
        return $this->unlockOrLockUsers($dropzone, true);
    }


    /**
     * @Route(
     *      "/{resourceId}/unlock/cancel",
     *      name="innova_collecticiel_unlock_cancel",
     *      requirements={"resourceId" = "\d+"}
     * )
     * @ParamConverter("dropzone",class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     *
     * @param \Innova\CollecticielBundle\Entity\Dropzone $dropzone
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @internal param $user
     * @internal param $userId
     */
    public function unlockUsersCancel(Dropzone $dropzone)
    {
        return $this->unlockOrLockUsers($dropzone, false);
    }


    /**
     *  Factorised function for lock & unlock users in a dropzone.
     * @param Dropzone $dropzone
     * @param bool $unlock
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    private function unlockOrLockUsers(Dropzone $dropzone, $unlock = true)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $drops = $dropRepo->findBy(array('dropzone' => $dropzone->getId(), 'unlockedUser' => !$unlock));


        foreach ($drops as $drop) {
            $drop->setUnlockedUser($unlock);
        }
        $em = $this->getDoctrine()->getManager();
        $em->flush();

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_examiners',
                array(
                    'resourceId' => $dropzone->getId()
                )
            )
        );
    }


    /**
     * @Route(
     *      "/{resourceId}/drops",
     *      name="innova_collecticiel_drops",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/by/default",
     *      name="innova_collecticiel_drops_by_default",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/by/default/{page}",
     *      name="innova_collecticiel_drops_by_default_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     *
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     **/
    public function dropsByDefaultAction($dropzone, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $dropsQuery = $dropRepo->getDropsFullyCorrectedOrderByReportAndDropDateQuery($dropzone);

        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_by_user_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        return $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'pager' => $pager,
            'unterminated_drops' => $countUnterminatedDrops,
        ));
    }

    /**
     *
     * @Route(
     *      "/{resourceId}/drops/by/report",
     *      name="innova_collecticiel_drops_by_report",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/by/report/{page}",
     *      name="innova_collecticiel_drops_by_report_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     *
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function dropsByReportAction($dropzone, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $dropsQuery = $dropRepo->getDropsFullyCorrectedReportedQuery($dropzone);

        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());

        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_by_user_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        return $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'pager' => $pager,
            'unterminated_drops' => $countUnterminatedDrops,
        ));
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/by/date",
     *      name="innova_collecticiel_drops_by_date",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/by/date/{page}",
     *      name="innova_collecticiel_drops_by_date_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function dropsByDateAction($dropzone, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');
        $dropsQuery = $dropRepo->getDropsFullyCorrectedOrderByDropDateQuery($dropzone);
        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());

        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_by_date_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        return $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'pager' => $pager,
            'unterminated_drops' => $countUnterminatedDrops,
        ));
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/awaiting",
     *      name="innova_collecticiel_drops_awaiting",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @Route(
     *      "/{resourceId}/drops/awaiting/{page}",
     *      name="innova_collecticiel_drops_awaiting_paginated",
     *      requirements={"resourceId" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function dropsAwaitingAction($dropzone, $page)
    {

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');

        // dropsQuery : finished à TRUE et unlocked_drop à FALSE
        $dropsQuery = $dropRepo->getDropsAwaitingCorrectionQuery($dropzone);

        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());

        // Déclarations des nouveaux tableaux, qui seront passés à la vue
        $userToCommentCount = array();
        $userNbTextToRead = array();

        foreach ($dropzone->getDrops() as $drop) {
            /** InnovaERV : ajout pour calculer les 2 zones **/

            // Nombre de commentaires non lus/ Repo : Comment
            $nbCommentsPerUser = $this->getDoctrine()
                                ->getRepository('InnovaCollecticielBundle:Comment')
                                ->countCommentNotRead($drop->getUser());

            // Nombre de devoirs à corriger/ Repo : Document
            $nbTextToRead = $this->getDoctrine()
                                ->getRepository('InnovaCollecticielBundle:Document')
                                ->countTextToRead($drop->getUser());

            // Affectations des résultats dans les tableaux
            $userToCommentCount[$drop->getUser()->getId()] = $nbCommentsPerUser;
            $userNbTextToRead[$drop->getUser()->getId()] = $nbTextToRead;
        }

        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_awaiting_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        $adminInnova = false;
        if ( $this->get('security.context')->isGranted('ROLE_ADMIN' === true)) {
            $adminInnova = true;
        }

        $dataToView = $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'unterminated_drops' => $countUnterminatedDrops,
            'pager' => $pager,
            'nbCommentNotRead' => $userToCommentCount,
            'userNbTextToRead' => $userNbTextToRead,
            'adminInnova' => $adminInnova,
        ));

        return $dataToView;
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/delete/{dropId}/{tab}/{page}",
     *      name="innova_collecticiel_drops_delete",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+", "tab" = "\d+", "page" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @Template()
     */
    public function dropsDeleteAction($dropzone, $drop, $tab, $page)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $form = $this->createForm(new DropType(), $drop);

        $previousPath = 'innova_collecticiel_drops_by_user_paginated';
        if ($tab == 1) {
            $previousPath = 'innova_collecticiel_drops_by_date_paginated';
        } elseif ($tab == 2) {
            $previousPath = 'innova_collecticiel_drops_awaiting_paginated';
        }

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $em = $this->getDoctrine()->getManager();
                $em->remove($drop);
                $em->flush();

                return $this->redirect(
                    $this->generateUrl(
                        $previousPath,
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $page
                        )
                    )

                );
            }
        }

        $view = 'InnovaCollecticielBundle:Drop:dropsDelete.html.twig';
        if ($this->getRequest()->isXmlHttpRequest()) {
            $view = 'InnovaCollecticielBundle:Drop:dropsDeleteModal.html.twig';
        }

        return $this->render($view, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'drop' => $drop,
            'form' => $form->createView(),
            'previousPath' => $previousPath,
            'tab' => $tab,
            'page' => $page
        ));
    }

    /**
     * @Route(
     *      "/{resourceId}/drops/detail/{dropId}",
     *      name="innova_collecticiel_drops_detail",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function dropsDetailAction($dropzone, $dropId)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropResult = $this
            ->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Drop')
            ->getDropAndCorrectionsAndDocumentsAndUser($dropzone, $dropId);

        $drop = null;
        $return = $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_awaiting',
                array(
                    'resourceId' => $dropzone->getId()
                )
            ));

        if (count($dropResult) > 0) {
            $drop = $dropResult[0];
            $return = array(
                'workspace' => $dropzone->getResourceNode()->getWorkspace(),
                '_resource' => $dropzone,
                'dropzone' => $dropzone,
                'drop' => $drop,
                'isAllowedToEdit' => true,
            );
        }

        return $return;
    }

    /**
     * @Route(
     *      "/{resourceId}/drop/detail/{dropId}",
     *      name="innova_collecticiel_drop_detail_by_user",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @Template()
     */
    public function dropDetailAction(Dropzone $dropzone, Drop $drop)
    {
        // check  if the User is allowed to open the dropZone.
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        // getting the userId to check if the current drop owner match with the loggued user.
        $userId = $this->get('security.context')->getToken()->getUser()->getId();
        $collection = new ResourceCollection(array($dropzone->getResourceNode()));
        $isAllowedToEdit = $this->get('security.context')->isGranted('EDIT', $collection);


        // getting the data
        $dropSecure = $this->getDoctrine()
            ->getRepository('InnovaCollecticielBundle:Drop')
            ->getDropAndValidEndedCorrectionsAndDocumentsByUser($dropzone, $drop->getId(), $userId);

        // if there is no result ( user is not the owner, or the drop has not ended Corrections , show 404)
        if (count($dropSecure) == 0) {
            if ($drop->getUser()->getId() != $userId) {
                throw new AccessDeniedException();
            }
        } else {
            $drop = $dropSecure[0];
        }

        $showCorrections = false;

        // if drop is complete and corrections needed were made  and dropzone.showCorrection is true.
        $user = $drop->getUser();
        $em = $this->getDoctrine()->getManager();
        $nbCorrections = $em
            ->getRepository('InnovaCollecticielBundle:Correction')
            ->countFinished($dropzone, $user);

        if ($dropzone->getDiplayCorrectionsToLearners() && $drop->countFinishedCorrections() >= $dropzone->getExpectedTotalCorrection() &&
            $dropzone->getExpectedTotalCorrection() <= $nbCorrections || ($dropzone->isFinished() && $dropzone->getDiplayCorrectionsToLearners() or $drop->getUnlockedUser())
        ) {
            $showCorrections = true;
        }

        return array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'drop' => $drop,
            'isAllowedToEdit' => $isAllowedToEdit,
            'showCorrections' => $showCorrections,
        );
    }

    /**
     * @param Drop $drop
     * @param User $user
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @Route(
     *      "/unlock/drop/{dropId}",
     *      name="innova_collecticiel_unlock_drop",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "This action requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function unlockDropAction(Drop $drop, User $user)
    {
        $em = $this->getDoctrine()->getManager();
        $drop->setUnlockedDrop(true);
        $em->flush();

        $this->getRequest()
            ->getSession()
            ->getFlashBag()
            ->add('success', $this->get('translator')->trans('Drop have been unlocked', array(), 'innova_collecticiel')
            );

        $dropzoneId = $drop->getDropzone()->getId();
        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_awaiting',
                array(
                    'resourceId' => $dropzoneId
                )
            )
        );
    }


    /**
     * @Route(
     *      "/report/drop/{correctionId}",
     *      name="innova_collecticiel_report_drop",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+", "correctionId" = "\d+"}
     * )
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @ParamConverter("user", options={
     *      "authenticatedUser" = true,
     *      "messageEnabled" = true,
     *      "messageTranslationKey" = "Participate in an evaluation requires authentication. Please login.",
     *      "messageTranslationDomain" = "innova_collecticiel"
     * })
     * @Template()
     */
    public function reportDropAction(Correction $correction, User $user)
    {
        $dropzone = $correction->getDropzone();
        $drop = $correction->getDrop();
        $em = $this->getDoctrine()->getManager();
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);

        try {
            $curent_user_correction = $em->getRepository('InnovaCollecticielBundle:Correction')->getNotFinished($dropzone, $user);
        } catch (NotFoundHttpException $e) {
            throw new AccessDeniedException();
        }

        if ($curent_user_correction == null || $curent_user_correction->getId() != $correction->getId()) {
            throw new AccessDeniedException();
        }
        $form = $this->createForm(new CorrectionReportType(), $correction);

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {

                $drop->setReported(true);
                $correction->setReporter(true);
                $correction->setEndDate(new \DateTime());
                $correction->setFinished(true);
                $correction->setTotalGrade(0);


                $em->persist($drop);
                $em->persist($correction);
                $em->flush();

                $this->dispatchDropReportEvent($dropzone, $drop, $correction);
                $this
                    ->getRequest()
                    ->getSession()
                    ->getFlashBag()
                    ->add('success', $this->get('translator')->trans('Your report has been saved', array(), 'innova_collecticiel'));


                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_open',
                        array(
                            'resourceId' => $dropzone->getId()
                        )
                    )
                );
            }
        }

        $view = 'InnovaCollecticielBundle:Drop:reportDrop.html.twig';
        if ($this->getRequest()->isXmlHttpRequest()) {
            $view = 'InnovaCollecticielBundle:Drop:reportDropModal.html.twig';
        }

        return $this->render($view, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'drop' => $drop,
            'correction' => $correction,
            'form' => $form->createView(),
        ));
    }

    protected function dispatchDropReportEvent(Dropzone $dropzone, Drop $drop, Correction $correction)
    {
        $rm = $this->get('claroline.manager.role_manager');
        $event = new LogDropReportEvent($dropzone, $drop, $correction, $rm);
        $this->get('event_dispatcher')->dispatch('log', $event);
    }


    /**
     * @Route(
     *      "/{resourceId}/remove/report/{dropId}/{correctionId}/{invalidate}",
     *      name="innova_collecticiel_remove_report",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+", "correctionId" = "\d+", "invalidate" = "0|1"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @ParamConverter("drop", class="InnovaCollecticielBundle:Drop", options={"id" = "dropId"})
     * @ParamConverter("correction", class="InnovaCollecticielBundle:Correction", options={"id" = "correctionId"})
     * @Template()
     */
    public function removeReportAction(Dropzone $dropzone, Drop $drop, Correction $correction, $invalidate)
    {

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $em = $this->getDoctrine()->getManager();
        $correction->setReporter(false);

        if ($invalidate == 1) {
            $correction->setValid(false);
        }

        $em->persist($correction);
        $em->flush();

        $correctionRepo = $this->getDoctrine()->getRepository('InnovaCollecticielBundle:Correction');
        if ($correctionRepo->countReporter($dropzone, $drop) == 0) {
            $drop->setReported(false);
            $em->persist($drop);
            $em->flush();
        }

        $event = new LogCorrectionUpdateEvent($dropzone, $drop, $correction);
        $this->dispatch($event);

        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_detail',
                array(
                    'resourceId' => $dropzone->getId(),
                    'dropId' => $drop->getId(),
                )
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/autoclosedrops/confirm",
     *      name="innova_collecticiel_auto_close_drops_confirmation",
     *      requirements={"resourceId" = "\d+", "dropId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function autoCloseDropsConfirmationAction($dropzone)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $view = 'InnovaCollecticielBundle:Dropzone:confirmCloseUnterminatedDrop.html.twig';
        if ($this->getRequest()->isXmlHttpRequest()) {
            $view = 'InnovaCollecticielBundle:Dropzone:confirmCloseUnterminatedDropModal.html.twig';
        }
        return $this->render($view, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
        ));
    }

    /**
     * @Route(
     *      "/{resourceId}/autoclosedrops",
     *      name="innova_collecticiel_auto_close_drops",
     *      requirements={"resourceId" = "\d+"}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     *
     */
    public function autoCloseDropsAction($dropzone)
    {
        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropzoneManager = $this->get('innova.manager.dropzone_manager');
        $dropzoneManager->closeDropzoneOpenedDrops($dropzone, true);


        return $this->redirect(
            $this->generateUrl(
                'innova_collecticiel_drops_awaiting',
                array(
                    'resourceId' => $dropzone->getId()
                )
            )
        );
    }

    /**
     * @Route(
     *      "/{resourceId}/shared/spaces",
     *      name="innova_collecticiel_shared_spaces",
     *      requirements={"resourceId" = "\d+"},
     *      defaults={"page" = 1}
     * )
     * @ParamConverter("dropzone", class="InnovaCollecticielBundle:Dropzone", options={"id" = "resourceId"})
     * @Template()
     */
    public function sharedSpacesAction($dropzone, $page)
    {

        $this->get('innova.manager.dropzone_voter')->isAllowToOpen($dropzone);
        $this->get('innova.manager.dropzone_voter')->isAllowToEdit($dropzone);

        $dropRepo = $this->getDoctrine()->getManager()->getRepository('InnovaCollecticielBundle:Drop');

        // dropsQuery : finished à TRUE et unlocked_drop à FALSE
        $dropsQuery = $dropRepo->getDropsAwaitingCorrectionQuery($dropzone);

        $countUnterminatedDrops = $dropRepo->countUnterminatedDropsByDropzone($dropzone->getId());

        // Déclarations des nouveaux tableaux, qui seront passés à la vue
        $userToCommentCount = array();
        $userNbTextToRead = array();

        foreach ($dropzone->getDrops() as $drop) {
            /** InnovaERV : ajout pour calculer les 2 zones **/

            // Nombre de commentaires non lus/ Repo : Comment
            $nbCommentsPerUser = $this->getDoctrine()
                                ->getRepository('InnovaCollecticielBundle:Comment')
                                ->countCommentNotRead($drop->getUser());

            // Nombre de devoirs à corriger/ Repo : Document
            $nbTextToRead = $this->getDoctrine()
                                ->getRepository('InnovaCollecticielBundle:Document')
                                ->countTextToRead($drop->getUser());

            // Affectations des résultats dans les tableaux
            $userToCommentCount[$drop->getUser()->getId()] = $nbCommentsPerUser;
            $userNbTextToRead[$drop->getUser()->getId()] = $nbTextToRead;
        }

        $adapter = new DoctrineORMAdapter($dropsQuery);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage(DropzoneBaseController::DROP_PER_PAGE);
        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            if ($page > 0) {
                return $this->redirect(
                    $this->generateUrl(
                        'innova_collecticiel_drops_awaiting_paginated',
                        array(
                            'resourceId' => $dropzone->getId(),
                            'page' => $pager->getNbPages()
                        )
                    )
                );
            } else {
                throw new NotFoundHttpException();
            }
        }

        $adminInnova = false;
        if ( $this->get('security.context')->isGranted('ROLE_ADMIN' === true)) {
            $adminInnova = true;
        }

        $dataToView = $this->addDropsStats($dropzone, array(
            'workspace' => $dropzone->getResourceNode()->getWorkspace(),
            '_resource' => $dropzone,
            'dropzone' => $dropzone,
            'unterminated_drops' => $countUnterminatedDrops,
            'pager' => $pager,
            'nbCommentNotRead' => $userToCommentCount,
            'userNbTextToRead' => $userNbTextToRead,
            'adminInnova' => $adminInnova,
        ));

        return $dataToView;
    }

}
