<?php

namespace FormaLibre\SupportBundle\Controller;

use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Manager\ToolManager;
use FormaLibre\SupportBundle\Entity\Comment;
use FormaLibre\SupportBundle\Entity\Ticket;
use FormaLibre\SupportBundle\Form\CommentType;
use FormaLibre\SupportBundle\Form\TicketType;
use FormaLibre\SupportBundle\Manager\SupportManager;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @DI\Tag("security.secure_service")
 */
class SupportController extends Controller
{
    private $authorization;
    private $formFactory;
    private $request;
    private $router;
    private $supportManager;
    private $toolManager;

    /**
     * @DI\InjectParams({
     *     "authorization"  = @DI\Inject("security.authorization_checker"),
     *     "formFactory"    = @DI\Inject("form.factory"),
     *     "requestStack"   = @DI\Inject("request_stack"),
     *     "router"         = @DI\Inject("router"),
     *     "supportManager" = @DI\Inject("formalibre.manager.support_manager"),
     *     "toolManager"    = @DI\Inject("claroline.manager.tool_manager")
     * })
     */
    public function __construct(
        AuthorizationCheckerInterface $authorization,
        FormFactory $formFactory,
        RequestStack $requestStack,
        RouterInterface $router,
        SupportManager $supportManager,
        ToolManager $toolManager
    )
    {
        $this->authorization = $authorization;
        $this->formFactory = $formFactory;
        $this->request = $requestStack->getCurrentRequest();
        $this->router = $router;
        $this->supportManager = $supportManager;
        $this->toolManager = $toolManager;
    }

    /**
     * @EXT\Route(
     *     "/support/index/page/{page}/max/{max}/ordered/by/{orderedBy}/order/{order}/search/{search}",
     *     name="formalibre_support_index",
     *     defaults={"page"=1, "search"="", "max"=50, "orderedBy"="num","order"="DESC"},
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template()
     */
    public function supportIndexAction(
        User $authenticatedUser,
        $search = '',
        $page = 1,
        $max = 50,
        $orderedBy = 'num',
        $order = 'DESC'
    )
    {
        $tickets = $this->supportManager->getTicketsByUser(
            $authenticatedUser,
            $search,
            $orderedBy,
            $order,
            true,
            $page,
            $max
        );
        $lastStatus = array();

        foreach ($tickets as $ticket) {
            $interventions = $ticket->getInterventions();
            $reverseInterventions = array_reverse($interventions);

            foreach ($reverseInterventions as $intervention) {
                $status = $intervention->getStatus();

                if (!is_null($status)) {
                    $lastStatus[$ticket->getId()] = $status;
                    break;
                }
            }
        }

        return array(
            'tickets' => $tickets,
            'lastStatus' => $lastStatus,
            'search' => $search,
            'page' => $page,
            'max' => $max,
            'orderedBy' => $orderedBy,
            'order' => $order
        );
    }

    /**
     * @EXT\Route(
     *     "ticket/create/form",
     *     name="formalibre_ticket_create_form",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketCreateForm.html.twig")
     */
    public function ticketCreateFormAction(User $authenticatedUser)
    {
        $ticket = new Ticket();
        $ticket->setUser($authenticatedUser);
        $ticket->setContactMail($authenticatedUser->getMail());
        $phone = $authenticatedUser->getPhone();

        if (!is_null($phone)) {
            $ticket->setContactPhone($phone);
        }
        $form = $this->formFactory->create(new TicketType(), $ticket);

        return array('form' => $form->createView());
    }

    /**
     * @EXT\Route(
     *     "ticket/create",
     *     name="formalibre_ticket_create",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketCreateForm.html.twig")
     */
    public function ticketCreateAction(User $authenticatedUser)
    {
        $ticket = new Ticket();
        $ticket->setUser($authenticatedUser);
        $form = $this->formFactory->create(new TicketType(), $ticket);
        $form->handleRequest($this->request);

        if ($form->isValid()) {
            $num = $this->supportManager->generateTicketNum($authenticatedUser);
            $ticket->setNum($num);
            $now = new \DateTime();
            $ticket->setCreationDate($now);
            $this->supportManager->persistTicket($ticket);

            return new RedirectResponse(
                $this->router->generate('formalibre_support_index')
            );
        } else {

            return array('form' => $form->createView());
        }
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/edit/form",
     *     name="formalibre_ticket_edit_form",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketEditForm.html.twig")
     */
    public function ticketEditFormAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketEditionAccess($authenticatedUser, $ticket);
        $form = $this->formFactory->create(new TicketType(), $ticket);

        return array(
            'form' => $form->createView(),
            'ticket' => $ticket
        );
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/edit",
     *     name="formalibre_ticket_edit",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketEditForm.html.twig")
     */
    public function ticketEditAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketEditionAccess($authenticatedUser, $ticket);
        $form = $this->formFactory->create(new TicketType(), $ticket);
        $form->handleRequest($this->request);

        if ($form->isValid()) {
            $this->supportManager->persistTicket($ticket);

            return new RedirectResponse(
                $this->router->generate('formalibre_support_index')
            );
        } else {

            return array(
                'form' => $form->createView(),
                'ticket' => $ticket
            );
        }
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/delete",
     *     name="formalibre_ticket_delete",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     */
    public function ticketDeleteAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketEditionAccess($authenticatedUser, $ticket);
        $this->supportManager->deleteTicket($ticket);

        return new JsonResponse('success', 200);
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/open",
     *     name="formalibre_ticket_open",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template()
     */
    public function ticketOpenAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketAccess($authenticatedUser, $ticket);
        $currentStatus = null;
        $interventions = $ticket->getInterventions();
        $reverseInterventions = array_reverse($interventions);

        foreach ($reverseInterventions as $intervention) {
            $status = $intervention->getStatus();

            if (!is_null($status)) {
                $currentStatus = $status;
                break;
            }
        }

        return array(
            'ticket' => $ticket,
            'currentUser' => $authenticatedUser,
            'currentStatus' => $currentStatus
        );
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/comment/create/form",
     *     name="formalibre_ticket_comment_create_form",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template()
     */
    public function ticketCommentCreateFormAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketAccess($authenticatedUser, $ticket);
        $form = $this->formFactory->create(new CommentType(), new Comment());

        return array('form' => $form->createView(), 'ticket' => $ticket);
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/comment/create",
     *     name="formalibre_ticket_comment_create",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketCommentCreateForm.html.twig")
     */
    public function ticketCommentCreateAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketAccess($authenticatedUser, $ticket);
        $comment = new Comment();
        $form = $this->formFactory->create(new CommentType(), $comment);
        $form->handleRequest($this->request);

        if ($form->isValid()) {
            $comment->setTicket($ticket);
            $comment->setUser($authenticatedUser);
            $comment->setIsAdmin(false);
            $comment->setCreationDate(new \DateTime());
            $this->supportManager->persistComment($comment);

            return new JsonResponse('success', 201);
        } else {

            return array('form' => $form->createView(), 'ticket' => $ticket);
        }
    }

    /**
     * @EXT\Route(
     *     "ticket/{ticket}/comments/view",
     *     name="formalibre_ticket_comments_view",
     *     options={"expose"=true}
     * )
     * @EXT\ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @EXT\Template("FormaLibreSupportBundle:Support:ticketCommentsModalView.html.twig")
     */
    public function ticketCommentsViewAction(User $authenticatedUser, Ticket $ticket)
    {
        $this->checkTicketAccess($authenticatedUser, $ticket);

        return array('ticket' => $ticket);
    }

    private function checkTicketAccess(User $user, Ticket $ticket)
    {
        if ($user->getId() !== $ticket->getUser()->getId()) {

            throw new AccessDeniedException();
        }
    }

    private function checkTicketEditionAccess(User $user, Ticket $ticket)
    {
        $interventions = $ticket->getInterventions();

        if ($user->getId() !== $ticket->getUser()->getId() || count($interventions) > 0) {

            throw new AccessDeniedException();
        }
    }
}
