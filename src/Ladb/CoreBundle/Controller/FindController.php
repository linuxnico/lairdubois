<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Manager\Find\FindManager;
use Ladb\CoreBundle\Manager\WitnessManager;
use Ladb\CoreBundle\Utils\JoinableUtils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Form\Type\Find\FindType;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Find\Content\Gallery;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\ReportableUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\PicturedUtils;
use Ladb\CoreBundle\Utils\PublicationUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\LocalisableUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;

/**
 * @Route("/trouvailles")
 */
class FindController extends Controller {

	/**
	 * @Route("/new", name="core_find_new")
	 * @Template()
	 */
	public function newAction() {

		$find = new Find();
		$form = $this->createForm(FindType::class, $find);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	/**
	 * @Route("/create", name="core_find_create")
	 * @Method("POST")
	 * @Template("LadbCoreBundle:Find:new.html.twig")
	 */
	public function createAction(Request $request) {
		$om = $this->getDoctrine()->getManager();

		$find = new Find();
		$form = $this->createForm(FindType::class, $find);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($find);

			$find->setUser($this->getUser());
			$this->getUser()->incrementDraftFindCount();

			$om->persist($find);
			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($find));

			return $this->redirect($this->generateUrl('core_find_show', array('id' => $find->getSluggedId())));
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
			'hideWarning'  => true,
		);
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_find_publish")
	 */
	public function publishAction($id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$find = $findRepository->findOneByIdJoinedOnUser($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $find->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_find_publish)');
		}
		if ($find->getIsDraft() === false) {
			throw $this->createNotFoundException('Already published (core_find_publish)');
		}

		// Publish
		$findManager = $this->get(FindManager::NAME);
		$findManager->publish($find);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('find.form.alert.publish_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_show', array( 'id' => $find->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_find_unpublish")
	 */
	public function unpublishAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$find = $findRepository->findOneByIdJoinedOnUser($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_find_unpublish)');
		}
		if ($find->getIsDraft() === true) {
			throw $this->createNotFoundException('Already draft (core_find_unpublish)');
		}

		// Unpublish
		$findManager = $this->get(FindManager::NAME);
		$findManager->unpublish($find);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('find.form.alert.unpublish_success', array( '%title%' => $find->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_find_edit")
	 * @Template()
	 */
	public function editAction($id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$find = $findRepository->findOneById($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $find->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_find_edit)');
		}

		$form = $this->createForm(FindType::class, $find);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, name="core_find_update")
	 * @Method("POST")
	 * @Template("LadbCoreBundle:Find:edit.html.twig")
	 */
	public function updateAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$find = $findRepository->findOneById($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $find->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_find_update)');
		}

		if ($find->getContent() instanceof Gallery) {
			$picturedUtils = $this->get(PicturedUtils::NAME);
			$picturedUtils->resetPictures($find->getContent()); // Reset pictures array to consider form pictures order
		}

		$previouslyUsedTags = $find->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(FindType::class, $find);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($find);

			if ($find->getUser()->getId() == $this->getUser()->getId()) {
				$find->setUpdatedAt(new \DateTime());
			}

			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_UPDATED, new PublicationEvent($find, array( 'previouslyUsedTags' => $previouslyUsedTags )));

			// Flashbag
			$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('find.form.alert.update_success', array( '%title%' => $find->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(FindType::class, $find);

		} else {

			// Flashbag
			$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		}

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'find'         => $find,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($find),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_find_delete")
	 */
	public function deleteAction($id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$find = $findRepository->findOneById($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && !($find->getIsDraft() === true && $find->getUser()->getId() == $this->getUser()->getId())) {
			throw $this->createNotFoundException('Not allowed (core_find_delete)');
		}

		// Delete
		$findManager = $this->get(FindManager::NAME);
		$findManager->delete($find);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('find.form.alert.delete_success', array( '%title%' => $find->getTitle() )));

		return $this->redirect($this->generateUrl('core_find_list'));
	}

	/**
	 * @Route("/{id}/location.geojson", name="core_find_location", defaults={"_format" = "json"})
	 * @Template("LadbCoreBundle:Find:location.geojson.twig")
	 */
	public function locationAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);

		$id = intval($id);

		$find = $findRepository->findOneById($id);
		if (is_null($find)) {
			throw $this->createNotFoundException('Unable to find Workshop entity (id='.$id.').');
		}
		if ($find->getIsDraft() === true) {
			throw $this->createNotFoundException('Not allowed (core_find_location)');
		}

		$features = array();
		$content = $find->getContent();
		if (!is_null($content->getLongitude()) && !is_null($content->getLatitude())) {
			$properties = array(
				'type' => 0,
			);
			$gerometry = new \GeoJson\Geometry\Point($content->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	/**
	 * @Route("/{filter}", requirements={"filter" = "[a-z-]+"}, name="core_find_list_filter")
	 * @Route("/{filter}/{page}", requirements={"filter" = "[a-z-]+", "page" = "\d+"}, name="core_find_list_filter_page")
	 * @Template()
	 */
	public function goneListAction(Request $request, $filter, $page = 0) {
		throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException();
	}

	/**
	 * @Route("/", name="core_find_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_find_list_page")
	 * @Template()
	 */
	public function listAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort) {
				switch ($facet->name) {

					case 'tag':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'tags.name' ));
						$filters[] = $filter;

						break;

					case 'author':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'user.displayname', 'user.fullname', 'user.username'  ));
						$filters[] = $filter;

						break;

					case 'kind':

						$filter = new \Elastica\Query\MatchPhrase('kind', $facet->value);
						$filters[] = $filter;

						break;

					case 'sort':

						switch ($facet->value) {

							case 'recent':
								$sort = array( 'changedAt' => array( 'order' => 'desc' ) );
								break;

							case 'popular-views':
								$sort = array( 'viewCount' => array( 'order' => 'desc' ) );
								break;

							case 'popular-likes':
								$sort = array( 'likeCount' => array( 'order' => 'desc' ) );
								break;

							case 'popular-comments':
								$sort = array( 'commentCount' => array( 'order' => 'desc' ) );
								break;

						}

						break;

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title', 'body', 'tags.name' ));
							$filters[] = $filter;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			'fos_elastica.index.ladb.find',
			\Ladb\CoreBundle\Entity\Find\Find::CLASS_NAME,
			'core_find_list_page'
		);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities']));

		$parameters = array_merge($searchParameters, array(
			'finds' => $searchParameters['entities'],
		));

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Find:list-xhr.html.twig', $parameters);
		}

		if ($this->get('security.authorization_checker')->isGranted('ROLE_USER') && $this->getUser()->getDraftFindCount() > 0) {

			$draftPath = $this->generateUrl('core_user_show_finds_filter', array( 'username' => $this->getUser()->getUsernameCanonical(), 'filter' => 'draft' ));
			$draftCount = $this->getUser()->getDraftFindCount();

			// Flashbag
			$this->get('session')->getFlashBag()->add('info', '<i class="ladb-icon-warning"></i> '.$this->get('translator')->transchoice('find.choice.draft_alert', $draftCount, array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$this->get('translator')->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Route("/{id}.html", name="core_find_show")
	 * @Template()
	 */
	public function showAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$findRepository = $om->getRepository(Find::CLASS_NAME);
		$witnessManager = $this->get(WitnessManager::NAME);

		$id = intval($id);

		$find = $findRepository->findOneById($id);
		if (is_null($find)) {
			if ($response = $witnessManager->checkResponse(Find::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Find entity (id='.$id.').');
		}
		if ($find->getIsDraft() === true) {
			if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && (is_null($this->getUser()) || $find->getUser()->getId() != $this->getUser()->getId())) {
				if ($response = $witnessManager->checkResponse(Find::TYPE, $id)) {
					return $response;
				}
				throw $this->createNotFoundException('Not allowed (core_find_show)');
			}
		}

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($find));

		$explorableUtils = $this->get(ExplorableUtils::NAME);
		$userFinds = $explorableUtils->getPreviousAndNextPublishedUserExplorables($find, $findRepository, $find->getUser()->getPublishedFindCount());
		$similarFinds = $explorableUtils->getSimilarExplorables($find, 'fos_elastica.index.ladb.find', Find::CLASS_NAME, $userFinds);

		$likableUtils = $this->get(LikableUtils::NAME);
		$watchableUtils = $this->get(WatchableUtils::NAME);
		$commentableUtils = $this->get(CommentableUtils::NAME);
		$followerUtils = $this->get(FollowerUtils::NAME);
		$joinableUtils = $this->get(JoinableUtils::NAME);

		if ($find->getContent() instanceof LocalisableInterface) {
			$hasMap = !is_null($find->getContent()->getLatitude()) && !is_null($find->getContent()->getLongitude());
		} else {
			$hasMap = false;
		}

		return array(
			'find'            => $find,
			'userFinds'       => $userFinds,
			'similarFinds'    => $similarFinds,
			'likeContext'     => $likableUtils->getLikeContext($find, $this->getUser()),
			'watchContext'    => $watchableUtils->getWatchContext($find, $this->getUser()),
			'commentContext'  => $commentableUtils->getCommentContext($find),
			'followerContext' => $followerUtils->getFollowerContext($find->getUser(), $this->getUser()),
			'joinContext'     => $joinableUtils->getJoinContext($find, $this->getUser()),
			'hasMap'          => $hasMap,
		);
	}

}