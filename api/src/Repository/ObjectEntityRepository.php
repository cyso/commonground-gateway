<?php

namespace App\Repository;

use App\Entity\Entity;
use App\Entity\ObjectEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @method ObjectEntity|null find($id, $lockMode = null, $lockVersion = null)
 * @method ObjectEntity|null findOneBy(array $criteria, array $orderBy = null)
 * @method ObjectEntity[]    findAll()
 * @method ObjectEntity[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ObjectEntityRepository extends ServiceEntityRepository
{
    private SessionInterface $session;

    public function __construct(ManagerRegistry $registry, SessionInterface $session)
    {
        $this->session = $session;

        parent::__construct($registry, ObjectEntity::class);
    }

    /**
     * @param Entity $entity
     * @param array  $filters
     * @param int    $offset
     * @param int    $limit
     *
     * @return ObjectEntity[] Returns an array of ObjectEntity objects
     */
    public function findByEntity(Entity $entity, array $filters = [], int $offset = 0, int $limit = 25): array
    {
        $query = $this->createQuery($entity, $filters);

//        var_dump($query->getDQL());

        return $query
            // filters toevoegen
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Entity $entity
     * @param array  $filters
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     *
     * @return int Returns an integer, for the total ObjectEntities found with this Entity and with the given filters.
     */
    public function countByEntity(Entity $entity, array $filters = []): int
    {
        $query = $this->createQuery($entity, $filters);
        $query->select('count(o)');

//        var_dump($query->getDQL());

        return $query->getQuery()->getSingleScalarResult();
    }

    private function createQuery(Entity $entity, array $filters): QueryBuilder
    {
        $query = $this->createQueryBuilder('o')
            ->andWhere('o.entity = :entity')
            ->setParameters(['entity' => $entity]);

        if (!empty($filters)) {
            $filterCheck = $this->getFilterParameters($entity);
            $query->leftJoin('o.objectValues', 'value');
            $level = 0;

            foreach ($filters as $key=>$value) {
                // Symfony has the tendency to replace . with _ on query parameters
                $key = str_replace(['_'], ['.'], $key);
                $key = str_replace(['..'], ['._'], $key);
                if (substr($key, 0, 1) == '.') {
                    $key = '_'.ltrim($key, $key[0]);
                }

                // We want to use custom logic for _ filters, because they will be used directly on the ObjectEntities themselves.
                if (substr($key, 0, 1) == '_') {
                    $query = $this->getObjectEntityFilter($query, $key, $value);
                    unset($filters[$key]); //todo: why unset if we never use filters after this?
                    continue;
                }
                // Lets see if this is an allowed filter
                if (!in_array($key, $filterCheck)) {
                    unset($filters[$key]); //todo: why unset if we never use filters after this?
                    continue;
                }

                // let not dive to deep
                if (!strpos($key, '.')) {
                    $query->andWhere('value.stringValue = :'.$key)
                        ->setParameter($key, $value);
                }
                /*@todo right now we only search on e level deep, lets make that 5 */
                else {
                    $key = explode('.', $key);
                    // only one deep right now
                    //if(count($key) == 2){
                    if ($level == 0) {
                        $level++;
                        //var_dump($key[0]);
                        //($key[1]);
                        $query->leftJoin('value.objects', 'subObjects'.$level);

                        // Deal with _ filters for subresources
                        if (substr($key[1], 0, 1) == '_' || $key[1] == 'id') {
                            $query = $this->getObjectEntityFilter($query, $key[1], $value, 'subObjects'.$level);
                            continue;
                        }
                        $query->leftJoin('subObjects'.$level.'.objectValues', 'subValue'.$level);
                    }
                    $query->andWhere('subValue'.$level.'.stringValue = :'.$key[1])->setParameter($key[1], $value);
                }

                // lets suport level 1
            }
        }

        // Multitenancy, only show objects this user is allowed to see.
        // TODO what if get(orgs) returns null/empty?
        if (empty($this->session->get('organizations'))) {
            $query->andWhere('o.organization IN (:organizations)')->setParameter('organizations', null);
        } else {
            $query->andWhere('o.organization IN (:organizations)')->setParameter('organizations', $this->session->get('organizations'));
        }

        // TODO filter for o.application?

//        var_dump($query->getDQL());

        return $query;
    }

    //todo: typecast?
    private function getObjectEntityFilter(QueryBuilder $query, $key, $value, string $prefix = 'o'): QueryBuilder
    {
//        var_dump('filter :');
//        var_dump($key);
//        var_dump($value);
        switch ($key){
            case 'id':
                $query->andWhere('('.$prefix.'.id = :'.$key.' OR '.$prefix.'.externalId = :'.$key.')')->setParameter($key, $value);
                break;
            case '_id':
                $query->andWhere($prefix.'.id = :id')->setParameter('id', $value);
                break;
            case '_externalId':
                $query->andWhere($prefix.'.externalId = :externalId')->setParameter('externalId', $value);
                break;
            case '_uri':
                $query->andWhere($prefix.'.uri = :uri')->setParameter('uri', $value);
                break;
            case '_organization':
                $query->andWhere($prefix.'.organization = :organization')->setParameter('externalId', $value);
                break;
            case '_application':
                $query->andWhere($prefix.'.application = :application')->setParameter('application', $value);
                break;
            case '_dateCreated':
                if (array_key_exists('from', $value)) {
                    $query->andWhere($prefix.'.dateCreated >= :dateCreated')->setParameter('dateCreated', $value['from']);
                } elseif (array_key_exists('till', $value)) {
                    $query->andWhere($prefix.'.dateCreated <= :dateCreated')->setParameter('dateCreated', $value['till']);
                } else {
                    //todo: error?
//                    var_dump('Not supported subfilter for _dateCreated');
                }
                break;
            case '_dateModified':
                if (array_key_exists('from', $value)) {
                    $query->andWhere($prefix.'.dateModified >= :dateModified')->setParameter('dateModified', $value['from']);
                } elseif (array_key_exists('till', $value)) {
                    $query->andWhere($prefix.'.dateModified <= :dateModified')->setParameter('dateModified', $value['till']);
                } else {
                    //todo: error?
//                    var_dump('Not supported subfilter for _dateCreated');
                }
                break;
            default:
                //todo: error?
//                var_dump('Not supported filter for ObjectEntity');
                break;
        }

        return $query;
    }

    private function getAllValues(string $atribute, string $value): array
    {
    }

    public function getFilterParameters(Entity $Entity, string $prefix = '', int $level = 1): array
    {
        // NOTE:
        // Filter id looks for ObjectEntity id and externalId
        // Filter _id looks specifically/only for ObjectEntity id
        // Filter _externalId looks specifically/only for ObjectEntity externalId
        if ($level != 1) {
            // For level 1 we should not allow filter id, because this is just a get Item call (not needed for a get collection)
            // Maybe we should do the same for _id & _externalId if we allow to use _ filters on subresources?
            $filters = [$prefix.'id'];
        }

        $filters = array_merge($filters ?? [], [
            $prefix.'_id', $prefix.'_externalId', $prefix.'_uri', $prefix.'_organization', $prefix.'_application',
            $prefix.'_dateCreated', $prefix.'_dateModified',
        ]);

        foreach ($Entity->getAttributes() as $attribute) {
            if ($attribute->getType() == 'string' && $attribute->getSearchable()) {
                $filters[] = $prefix.$attribute->getName();
            } elseif ($attribute->getObject() && $level < 5 && !str_contains($prefix, $attribute->getName().'.')) {
                $filters = array_merge($filters, $this->getFilterParameters($attribute->getObject(), $prefix.$attribute->getName().'.', $level + 1));
            }
            continue;
        }

        return $filters;
    }

    // Filter functie schrijven, checken op betaande atributen, zelf looping
    // voorbeeld filter student.generaldDesription.landoforigen=NL
    //                  entity.atribute.propert['name'=landoforigen]
    //                  (objectEntity.value.objectEntity.value.name=landoforigen and
    //                  objectEntity.value.objectEntity.value.value=nl)

    /*
    public function findOneBySomeField($value): ?ObjectEntity
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
