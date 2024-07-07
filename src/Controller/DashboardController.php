<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use App\Entity\Post;
use App\Entity\Comment;
use App\Entity\Like;
use App\Entity\Tags;
use App\Form\PostType;
use App\Form\CommentType;
use App\Form\LikepostType;
use App\Form\DislikepostType;
use App\Form\TagType;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\LikeRepository;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;


class DashboardController extends AbstractController
{
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }
    #[Route('add_post', name: 'post_add')]
    public function addPost(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->security->getUser();
        if (!$user) {
            return $this->render('dashboard/error.html.twig', [
                'errors' => ['You have to be logged in to add post.'],
            ]);
        }
        else {
        $post = new Post();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $post->setUser($user);

            $entityManager->persist($post);
            $entityManager->flush();

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('dashboard/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
    #[Route('/post_show/{id}/edit', name: 'post_edit')]
    public function edit($id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $post = $entityManager->getRepository(Post::class)->find($id);

        if (!$post) {
            return $this->render('dashboard/error.html.twig', [
                'errors' => ['Post not found.'],
            ]);
        }
        if ($this->getUser() !== $post->getUser()) {
            return $this->render('dashboard/error.html.twig', [
                'errors' => ['You are not allowed to edit this post.'],
            ]);
        }

        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('dashboard/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    #[Route('/', name: 'app_dashboard')]
    public function show(Environment $twig, PostRepository $postRepository): Response
    {
        return new Response($twig->render('dashboard/index.html.twig', [
            'user' => $this->getUser(),
            'posts' => $postRepository->findAll(),
        ]));
    }
    #[Route('/post_show/{id}', name: 'post_show')]
    public function showPost(Environment $twig, PostRepository $postRepository, $id, Request $request, EntityManagerInterface $entityManager, LikeRepository $likeRepository): Response
    {
        $post = $postRepository->find($id);
        $comment = new Comment();
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->security->getUser();
            $comment->setUser($user);
            $comment->setPost($post);

            $entityManager->persist($comment);
            $entityManager->flush();

            return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }

        $comments = $entityManager->getRepository(Comment::class)->findBy(['post' => $post]);
        $likes = $entityManager->getRepository(Like::class)->countLikesByPost($id);
        $user = $this->security->getUser();
        $likeForm = $this->createForm(LikepostType::class);
        $dislikeForm = $this->createForm(DislikepostType::class);
        $hasLiked = false;

        if ($user) {
            $hasLiked = $likeRepository->hasUserLikedPost($id, $user->getId());
            $like = $likeRepository->findUserLikeForPost($id, $user->getId());
        }

        $dislikeForm->handleRequest($request);
        if ($dislikeForm->isSubmitted() && $dislikeForm->isValid() && $hasLiked && $user) {
            $entityManager->remove($like);
            $entityManager->flush();

            return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }
            
        $likeForm->handleRequest($request);

        if ($likeForm->isSubmitted() && $likeForm->isValid() && !$hasLiked && $user) {
            $like = new Like();
            $like->setUser($user);
            $like->setPost($post);

            $entityManager->persist($like);
            $entityManager->flush();

            return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }

        $tag = new Tags();
        $tagForm = $this->createForm(TagType::class, $tag);
        $tagForm->handleRequest($request);

        if ($tagForm->isSubmitted() && $tagForm->isValid()) {
            $tag = $tagForm->getData();

            $existingTag = $entityManager->getRepository(Tags::class)->findOneBy(['title' => $tag->getTitle()]);

            if ($existingTag) {
                if (!$post->getTags()->contains($existingTag))
                    $post->addTag($existingTag);
            } else {
                $entityManager->persist($tag);
                $post->addTag($tag);
    }

    $entityManager->persist($post);
    $entityManager->flush();

    return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
        }
        
        $deleteTagForms = [];
        foreach ($post->getTags() as $tag) {
            $formm = $this->createFormBuilder()
                ->setAction($this->generateUrl('post_remove_tag', ['postId' => $post->getId(), 'tagId' => $tag->getId()]))
                ->add('postId', HiddenType::class, ['data' => $post->getId()])
                ->add('tagId', HiddenType::class, ['data' => $tag->getId()])
                ->getForm();
            $deleteTagForms[$tag->getId()] = $formm->createView();
        }

        return $this->render('dashboard/show.html.twig', [
            'user' => $post->getUser(),
            'post' => $post,
            'comments' => $comments,
            'form' => $form->createView(),
            'likes' => $likes,
            'likeForm' => $likeForm->createView(),
            'dislikeForm' => $dislikeForm->createView(),
            'hasLiked' => $hasLiked,
            'tags' => $post->getTags(),
            'tagForm' => $tagForm->createView(),
            'deleteTagForms' => $deleteTagForms,
        ]);
    }
    #[Route('/{id}/delete', name: 'post_delete')]
    public function deletePost(PostRepository $postRepository, EntityManagerInterface $entityManager, $id): Response
    {
        $post = $postRepository->find($id);
        if ($this->getUser() !== $post->getUser()) {
            return $this->render('dashboard/error.html.twig', [
                'errors' => ['You are not allowed to delete this post.'],
            ]);
        }
        if (!$post) {
            return $this->render('dashboard/error.html.twig', [
                'error' => ['Post not found'],
            ]);
        }
        $likes = $entityManager->getRepository(Like::class)->findBy(['post' => $post]);
        foreach ($likes as $like) {
            $entityManager->remove($like);
        }
        $entityManager->remove($post);
        $entityManager->flush();

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/post/{postId}/remove-tag/{tagId}', name: 'post_remove_tag')]
    public function removeTag(int $postId, int $tagId, EntityManagerInterface $entityManager): Response
    {
        $post = $entityManager->getRepository(Post::class)->find($postId);
        if ($this->getUser() !== $post->getUser()) {
            return $this->render('dashboard/error.html.twig', [
                'errors' => ['You are not allowed to delete this tag.'],
            ]);
        }
        $tag = $entityManager->getRepository(Tags::class)->find($tagId);

        if (!$post || !$tag) {
            throw $this->createNotFoundException('Post or Tag not found');
        }

        $post->removeTag($tag);
        $entityManager->flush();

        return $this->redirectToRoute('post_show', ['id' => $postId]);
    }
}

