<?php

namespace Ladb\CoreBundle\Controller;

use Ladb\CoreBundle\Manager\Knowledge\ProviderManager;
use Ladb\CoreBundle\Manager\WitnessManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Form\Type\Knowledge\NewProviderType;
use Ladb\CoreBundle\Form\Model\NewProvider;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\ReportableUtils;
use Ladb\CoreBundle\Utils\VotableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\ElasticaQueryUtils;
use Ladb\CoreBundle\Utils\PropertyUtils;
use Ladb\CoreBundle\Utils\PublicationUtils;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\KnowledgeEvent;
use Ladb\CoreBundle\Event\KnowledgeListener;

/**
 * @Route("/fournisseurs")
 */
class ProviderController extends Controller {

	/**
	 * @Route("/new", name="core_provider_new")
	 * @Template()
	 */
	public function newAction() {

		$newProvider = new NewProvider();
		$form = $this->createForm(NewProviderType::class, $newProvider);

		return array(
			'form' => $form->createView(),
		);
	}

	/**
	 * @Route("/create", name="core_provider_create")
	 * @Method("POST")
	 * @Template("LadbCoreBundle:Provider:new.html.twig")
	 */
	public function createAction(Request $request) {
		$om = $this->getDoctrine()->getManager();
		$dispatcher = $this->get('event_dispatcher');

		$newProvider = new NewProvider();
		$form = $this->createForm(NewProviderType::class, $newProvider);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$signValue = $newProvider->getSignValue();
			$logoValue = $newProvider->getLogoValue();
			$user = $this->getUser();

			$provider = new Provider();
			$provider->setSign($signValue->getData());
			$provider->setBrand($signValue->getBrand());
			$provider->setStore($signValue->getStore());
			$provider->incrementContributorCount();

			$om->persist($provider);
			$om->flush();	// Need to save provider to be sure ID is generated

			$provider->addSignValue($signValue);
			$provider->addLogoValue($logoValue);

			// Dispatch knowledge events
			$dispatcher->dispatch(KnowledgeListener::FIELD_VALUE_ADDED, new KnowledgeEvent($provider, array( 'field' => Provider::FIELD_SIGN, 'value' => $signValue )));
			$dispatcher->dispatch(KnowledgeListener::FIELD_VALUE_ADDED, new KnowledgeEvent($provider, array( 'field' => Provider::FIELD_LOGO, 'value' => $logoValue )));

			$signValue->setParentEntity($provider);
			$signValue->setParentEntityField(Provider::FIELD_SIGN);
			$signValue->setUser($user);

			$logoValue->setParentEntity($provider);
			$logoValue->setParentEntityField(Provider::FIELD_LOGO);
			$logoValue->setUser($user);

			$user->incrementProposalCount(2);	// Sign and Logo of this new provider

			$provider->setIsDraft(false);

			// Create activity
			$activityUtils = $this->get(ActivityUtils::NAME);
			$activityUtils->createContributeActivity($signValue, false);
			$activityUtils->createContributeActivity($logoValue, false);

			$om->flush();

			// Dispatch publication event
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($provider));

			return $this->redirect($this->generateUrl('core_provider_show', array('id' => $provider->getSluggedId())));
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		return array(
			'newProvider' => $newProvider,
			'form'        => $form->createView(),
			'hideWarning' => true,
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_provider_delete")
	 */
	public function deleteAction($id) {
		$propertyUtils = $this->get(PropertyUtils::NAME);
		$om = $this->getDoctrine()->getManager();
		$providerRepository = $om->getRepository(Provider::CLASS_NAME);

		$provider = $providerRepository->findOneById($id);
		if (is_null($provider)) {
			throw $this->createNotFoundException('Unable to find Provider entity (id='.$id.').');
		}
		if (!$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN')) {
			throw $this->createNotFoundException('Not allowed (core_provider_delete)');
		}

		// Delete
		$providerManager = $this->get(ProviderManager::NAME);
		$providerManager->delete($provider);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('knowledge.provider.form.alert.delete_success', array( '%title%' => $provider->getTitle() )));

		return $this->redirect($this->generateUrl('core_provider_list'));
	}

	/**
	 * @Route("/{id}/location.geojson", name="core_provider_location", defaults={"_format" = "json"})
	 * @Template("LadbCoreBundle:Provider:location.geojson.twig")
	 */
	public function locationAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$providerRepository = $om->getRepository(Provider::CLASS_NAME);

		$id = intval($id);

		$provider = $providerRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($provider)) {
			throw $this->createNotFoundException('Unable to find Provider entity (id='.$id.').');
		}

		$features = array();
		if (!is_null($provider->getLongitude()) && !is_null($provider->getLatitude())) {
			$properties = array(
				'type' => 0,
			);
			$gerometry = new \GeoJson\Geometry\Point($provider->getGeoPoint());
			$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
		}

		$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
		$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

		return array(
			'collection' => $collection,
		);
	}

	/**
	 * @Route("/", name="core_provider_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_provider_list_page")
	 * @Route(".geojson", defaults={"_format" = "json", "page"=-1, "layout"="geojson"}, name="core_provider_list_geojson")
	 * @Template()
	 */
	public function listAction(Request $request, $page = 0, $layout = 'view') {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort) {
				switch ($facet->name) {

					case 'brand':

						$filter = new \Elastica\Query\Match('brand', $facet->value);
						$filters[] = $filter;

						break;

					case 'location':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'address', 'geographicalAreas' ));
						$filters[] = $filter;

						break;

					case 'around':

						if (isset($facet->value)) {
							$filter = new \Elastica\Query\Filtered(null, new \Elastica\Filter\GeoDistance('geoPoint', $facet->value, '100km'));
							$filters[] = $filter;
						}

						break;

					case 'products':

						$filter = new \Elastica\Query\QueryString('"'.$facet->value.'"');
						$filter->setFields(array( 'products' ));
						$filters[] = $filter;

						break;

					case 'services':

						$filter = new \Elastica\Query\QueryString('"'.$facet->value.'"');
						$filter->setFields(array( 'services' ));
						$filters[] = $filter;

						break;

					case 'woods':

						$elasticaQueryUtils = $this->get(ElasticaQueryUtils::NAME);
						$query1 = new \Elastica\Query\QueryString('"Bois massif"');
						$query1->setFields(array( 'products' ));
						$query2 = $elasticaQueryUtils->createShouldMatchPhraseQuery('woods', $facet->value);
						$filter = new \Elastica\Query\Bool();
						$filter->addMust($query1);
						$filter->addMust($query2);
						$filters[] = $filter;

						break;

					case 'in-store-selling':

						$filter = new \Elastica\Query\Range('inStoreSelling', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'mail-order-selling':

						$filter = new \Elastica\Query\Range('mailOrderSelling', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'sale-to-individuals':

						$filter = new \Elastica\Query\Range('saleToIndividuals', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'pro-only':

						$filter = new \Elastica\Query\Range('saleToIndividuals', array( 'lt' => 1 ));
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
							$filter->setFields(array( 'sign', 'geographicalAreas', 'products', 'services', 'description' ));
							$filters[] = $filter;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			'fos_elastica.index.ladb.provider',
			\Ladb\CoreBundle\Entity\Knowledge\Provider::CLASS_NAME,
			'core_provider_list_page'
		);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities']));

		$parameters = array_merge($searchParameters, array(
			'providers' => $searchParameters['entities'],
		));

		if ($layout == 'geojson') {

			$features = array();
			foreach ($searchParameters['entities'] as $provider) {
				if (is_null($provider->getLongitude()) || is_null($provider->getLatitude())) {
					continue;
				}
				$properties = array(
					'type'    => 0,
					'cardUrl' => $this->generateUrl('core_provider_card', array( 'id' => $provider->getId() )),
				);
				$gerometry = new \GeoJson\Geometry\Point($provider->getGeoPoint());
				$features[] = new \GeoJson\Feature\Feature($gerometry, $properties);
			}
			$crs = new \GeoJson\CoordinateReferenceSystem\Named('urn:ogc:def:crs:OGC:1.3:CRS84');
			$collection = new \GeoJson\Feature\FeatureCollection($features, $crs);

			$parameters = array_merge($parameters, array(
				'collection' => $collection,
			));

			return $this->render('LadbCoreBundle:Provider:list-xhr.geojson.twig', $parameters);
		}

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Provider:list-xhr.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/{id}/card.xhr", name="core_provider_card")
	 * @Template("LadbCoreBundle:Provider:card-xhr.html.twig")
	 */
	public function cardAction(Request $request, $id) {
		if (!$request->isXmlHttpRequest()) {
			throw $this->createNotFoundException('Only XML request allowed.');
		}

		$om = $this->getDoctrine()->getManager();
		$providerRepository = $om->getRepository(Provider::CLASS_NAME);

		$id = intval($id);

		$provider = $providerRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($provider)) {
			throw $this->createNotFoundException('Unable to find Provider entity.');
		}

		return array(
			'provider' => $provider,
		);
	}

	/**
	 * @Route("/{id}.html", name="core_provider_show")
	 * @Template()
	 */
	public function showAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$providerRepository = $om->getRepository(Provider::CLASS_NAME);
		$witnessManager = $this->get(WitnessManager::NAME);

		$id = intval($id);

		$provider = $providerRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($provider)) {
			if ($response = $witnessManager->checkResponse(Provider::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Provider entity.');
		}

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($provider));

		$searchUtils = $this->get(SearchUtils::NAME);
		$searchableStoreCount = $searchUtils->searchEntitiesCount(array( new \Elastica\Query\Match('brand', $provider->getBrand()) ), null, 'fos_elastica.index.ladb.provider');
		$searchableWoodCount = $searchUtils->searchEntitiesCount(array( $this->get(ElasticaQueryUtils::NAME)->createShouldMatchPhraseQuery('name', $provider->getWoods()) ), null, 'fos_elastica.index.ladb.wood');

		$likableUtils = $this->get(LikableUtils::NAME);
		$watchableUtils = $this->get(WatchableUtils::NAME);
		$commentableUtils = $this->get(CommentableUtils::NAME);

		return array(
			'provider'             => $provider,
			'searchableStoreCount' => $searchableStoreCount,
			'searchableWoodCount'  => $searchableWoodCount,
			'likeContext'          => $likableUtils->getLikeContext($provider, $this->getUser()),
			'watchContext'         => $watchableUtils->getWatchContext($provider, $this->getUser()),
			'commentContext'       => $commentableUtils->getCommentContext($provider),
			'hasMap'               => !is_null($provider->getLatitude()) && !is_null($provider->getLongitude()),
		);
	}

}
