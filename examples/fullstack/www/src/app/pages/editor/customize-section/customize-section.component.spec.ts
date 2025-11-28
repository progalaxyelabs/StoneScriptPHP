import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CustomizeSectionComponent } from './customize-section.component';

describe('CustomizeSectionComponent', () => {
  let component: CustomizeSectionComponent;
  let fixture: ComponentFixture<CustomizeSectionComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [CustomizeSectionComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(CustomizeSectionComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
