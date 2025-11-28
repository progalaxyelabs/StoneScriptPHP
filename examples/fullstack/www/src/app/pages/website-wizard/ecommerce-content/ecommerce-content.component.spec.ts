import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EcommerceContentComponent } from './ecommerce-content.component';

describe('EcommerceContentComponent', () => {
  let component: EcommerceContentComponent;
  let fixture: ComponentFixture<EcommerceContentComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [EcommerceContentComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(EcommerceContentComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
