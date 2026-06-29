import { ChevronLeft, ChevronRight } from "lucide-react";
import type { KeyboardEvent } from "react";
import { useEffect, useRef, useState } from "react";
import "./Gallery.css";

export type GalleryItem = {
  src: string;
  srcSet: string;
  sizes: string;
  width: number;
  height: number;
  title: string;
  alt: string;
  className: string;
};

type GalleryProps = {
  items: GalleryItem[];
};

export default function Gallery({ items }: GalleryProps) {
  const [activeIndex, setActiveIndex] = useState(0);
  const trackRef = useRef<HTMLDivElement | null>(null);
  const slideRefs = useRef<Array<HTMLElement | null>>([]);
  const currentRenderedIndexRef = useRef<number | null>(null);
  const scrollFrameRef = useRef<number | null>(null);
  const scrollSyncTimeoutRef = useRef<number | null>(null);
  const scrollIdleTimeoutRef = useRef<number | null>(null);
  const isProgrammaticScrollRef = useRef(false);

  const hasCarousel = items.length > 1;
  const renderedItems = hasCarousel
    ? [0, 1, 2].flatMap((blockIndex) =>
        items.map((item, itemIndex) => ({
          item,
          realIndex: itemIndex,
          isClone: blockIndex !== 1,
          key: `${blockIndex}-${item.title}`,
        })),
      )
    : items.map((item, itemIndex) => ({
        item,
        realIndex: itemIndex,
        isClone: false,
        key: item.title,
      }));

  const getCircularIndex = (index: number) => {
    if (items.length === 0) {
      return 0;
    }

    return ((index % items.length) + items.length) % items.length;
  };

  const getRenderedIndexForRealIndex = (index: number) => {
    return hasCarousel ? items.length + getCircularIndex(index) : getCircularIndex(index);
  };

  const getResetRenderedIndex = (renderedIndex: number, realIndex: number) => {
    if (!hasCarousel) {
      return null;
    }

    const originalStart = items.length;
    const originalEnd = items.length * 2;

    if (renderedIndex >= originalStart && renderedIndex < originalEnd) {
      return null;
    }

    return getRenderedIndexForRealIndex(realIndex);
  };

  const getNearestRenderedSlide = () => {
    const track = trackRef.current;

    if (!track) {
      return null;
    }

    const trackRect = track.getBoundingClientRect();
    const trackCenter = trackRect.left + trackRect.width / 2;
    let nearestRenderedIndex = 0;
    let nearestDistance = Number.POSITIVE_INFINITY;

    slideRefs.current.forEach((slide, renderedIndex) => {
      if (!slide) {
        return;
      }

      const slideRect = slide.getBoundingClientRect();
      const slideCenter = slideRect.left + slideRect.width / 2;
      const distance = Math.abs(slideCenter - trackCenter);

      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestRenderedIndex = renderedIndex;
      }
    });

    return {
      distance: nearestDistance,
      realIndex: renderedItems[nearestRenderedIndex]?.realIndex ?? 0,
      renderedIndex: nearestRenderedIndex,
    };
  };

  const syncActiveIndexFromScroll = () => {
    const nearestSlide = getNearestRenderedSlide();

    if (!nearestSlide) {
      return;
    }

    currentRenderedIndexRef.current = nearestSlide.renderedIndex;
    setActiveIndex(nearestSlide.realIndex);
  };

  const centerRenderedIndex = (renderedIndex: number, behavior: ScrollBehavior) => {
    const track = trackRef.current;
    const nextSlide = slideRefs.current[renderedIndex];

    if (!track || !nextSlide) {
      return false;
    }

    const maxScrollLeft = Math.max(track.scrollWidth - track.clientWidth, 0);
    const targetScrollLeft = Math.min(
      Math.max(nextSlide.offsetLeft - (track.clientWidth - nextSlide.offsetWidth) / 2, 0),
      maxScrollLeft,
    );

    track.scrollTo({
      left: targetScrollLeft,
      behavior,
    });

    return true;
  };

  const scrollToRenderedIndex = (
    renderedIndex: number,
    realIndex: number,
    resetRenderedIndex: number | null = null,
  ) => {
    const shouldReduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const behavior: ScrollBehavior = shouldReduceMotion ? "auto" : "smooth";

    setActiveIndex(realIndex);

    if (!centerRenderedIndex(renderedIndex, behavior)) {
      return;
    }

    currentRenderedIndexRef.current = renderedIndex;
    isProgrammaticScrollRef.current = true;

    if (scrollSyncTimeoutRef.current) {
      window.clearTimeout(scrollSyncTimeoutRef.current);
    }

    scrollSyncTimeoutRef.current = window.setTimeout(() => {
      if (resetRenderedIndex !== null) {
        centerRenderedIndex(resetRenderedIndex, "auto");
        currentRenderedIndexRef.current = resetRenderedIndex;
      }

      isProgrammaticScrollRef.current = false;
      syncActiveIndexFromScroll();
    }, shouldReduceMotion ? 0 : 520);
  };

  const scrollToIndex = (index: number) => {
    const nextIndex = getCircularIndex(index);
    scrollToRenderedIndex(getRenderedIndexForRealIndex(nextIndex), nextIndex);
  };

  const scrollByStep = (step: number) => {
    const baseRenderedIndex =
      currentRenderedIndexRef.current ?? getRenderedIndexForRealIndex(activeIndex);
    const nextRenderedIndex = baseRenderedIndex + step;
    const nextIndex =
      renderedItems[nextRenderedIndex]?.realIndex ?? getCircularIndex(activeIndex + step);
    const targetRenderedIndex = renderedItems[nextRenderedIndex]
      ? nextRenderedIndex
      : getRenderedIndexForRealIndex(nextIndex);

    scrollToRenderedIndex(
      targetRenderedIndex,
      nextIndex,
      getResetRenderedIndex(targetRenderedIndex, nextIndex),
    );
  };

  const handleScroll = () => {
    if (isProgrammaticScrollRef.current) {
      return;
    }

    if (scrollFrameRef.current) {
      window.cancelAnimationFrame(scrollFrameRef.current);
    }

    scrollFrameRef.current = window.requestAnimationFrame(() => {
      scrollFrameRef.current = null;
      syncActiveIndexFromScroll();
    });

    if (scrollIdleTimeoutRef.current) {
      window.clearTimeout(scrollIdleTimeoutRef.current);
    }

    scrollIdleTimeoutRef.current = window.setTimeout(() => {
      const nearestSlide = getNearestRenderedSlide();

      if (!nearestSlide) {
        return;
      }

      const resetRenderedIndex = getResetRenderedIndex(
        nearestSlide.renderedIndex,
        nearestSlide.realIndex,
      );

      if (resetRenderedIndex !== null) {
        centerRenderedIndex(resetRenderedIndex, "auto");
        currentRenderedIndexRef.current = resetRenderedIndex;
      }
    }, 160);
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      scrollByStep(-1);
    }

    if (event.key === "ArrowRight") {
      event.preventDefault();
      scrollByStep(1);
    }
  };

  useEffect(() => {
    const nextIndex = getCircularIndex(activeIndex);
    const initialScrollFrame = window.requestAnimationFrame(() => {
      centerRenderedIndex(getRenderedIndexForRealIndex(nextIndex), "auto");
      currentRenderedIndexRef.current = getRenderedIndexForRealIndex(nextIndex);
      setActiveIndex(nextIndex);
      syncActiveIndexFromScroll();
    });

    return () => {
      window.cancelAnimationFrame(initialScrollFrame);

      if (scrollFrameRef.current) {
        window.cancelAnimationFrame(scrollFrameRef.current);
      }

      if (scrollSyncTimeoutRef.current) {
        window.clearTimeout(scrollSyncTimeoutRef.current);
      }

      if (scrollIdleTimeoutRef.current) {
        window.clearTimeout(scrollIdleTimeoutRef.current);
      }
    };
  }, [items]);

  if (items.length === 0) {
    return null;
  }

  const renderControls = (modifierClassName: string) => {
    if (!hasCarousel) {
      return null;
    }

    return (
      <div
        className={`gallery__controls ${modifierClassName}`}
        aria-label="Управление галереей"
      >
        <button
          className="gallery__arrow gallery__arrow--prev"
          type="button"
          aria-controls="gallery-track"
          aria-label="Предыдущее фото"
          title="Предыдущее фото"
          onClick={() => scrollByStep(-1)}
        >
          <ChevronLeft aria-hidden="true" size={24} strokeWidth={1.8} />
        </button>
        <button
          className="gallery__arrow gallery__arrow--next"
          type="button"
          aria-controls="gallery-track"
          aria-label="Следующее фото"
          title="Следующее фото"
          onClick={() => scrollByStep(1)}
        >
          <ChevronRight aria-hidden="true" size={24} strokeWidth={1.8} />
        </button>
      </div>
    );
  };

  return (
    <section
      id="gallery"
      className="gallery"
      aria-labelledby="gallery-title"
      aria-roledescription="carousel"
    >
      <div className="gallery__shell container">
        <div className="gallery__header" data-animate="rise-sm">
          <h2 className="gallery__title" id="gallery-title">
            Галерея
          </h2>

          {renderControls("gallery__controls--header")}
        </div>

        <div className="gallery__stage" data-animate="rise-sm">
          {renderControls("gallery__controls--stage")}

          <div
            className="gallery__track"
            id="gallery-track"
            ref={trackRef}
            aria-label="Фотографии ресторана Сансара"
            tabIndex={0}
            onKeyDown={handleKeyDown}
            onScroll={handleScroll}
          >
            {renderedItems.map(({ item, realIndex, isClone, key }, renderedIndex) => (
              <figure
                className="gallery__item"
                data-active={realIndex === activeIndex ? "true" : undefined}
                aria-hidden={isClone ? true : undefined}
                key={key}
                ref={(element) => {
                  slideRefs.current[renderedIndex] = element;
                }}
              >
                <img
                  src={item.src}
                  srcSet={item.srcSet}
                  sizes={item.sizes}
                  width={item.width}
                  height={item.height}
                  alt={isClone ? "" : item.alt}
                  className={`gallery__photo ${item.className}`}
                  loading="lazy"
                  decoding="async"
                />
              </figure>
            ))}
          </div>
        </div>

        {hasCarousel && (
          <div className="gallery__dots" aria-label="Выбор фотографии" data-animate="fade">
            {items.map((item, index) => (
              <button
                className="gallery__dot"
                type="button"
                aria-label={`Показать фото: ${item.title}`}
                aria-pressed={index === activeIndex}
                key={item.title}
                onClick={() => scrollToIndex(index)}
              />
            ))}
          </div>
        )}
      </div>
    </section>
  );
}
